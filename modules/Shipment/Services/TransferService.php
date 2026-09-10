<?php

declare(strict_types=1);

namespace Modules\Shipment\Services;

use App\Support\CourierStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Shipment\Models\Shipment;

/**
 * TransferService
 * ---------------
 *
 * Practical branch-to-branch transfer flow, built on shipment statuses only
 * (no coverage_locations batch tables). Two actions:
 *
 *   dispatch()            sorted_for_transfer -> in_transit
 *                         (parcel leaves the origin branch toward destination)
 *
 *   receiveAtDestination() in_transit -> received_at_destination_branch
 *                         (arrives at destination; current branch set to
 *                          destination, then a pending last-mile delivery is
 *                          opened there so it appears on that branch's
 *                          deliveries board).
 */
final class TransferService
{
    public function __construct(
        private readonly ShipmentSortingService $sorting,
    ) {
    }

    /**
     * Dispatch one transfer shipment (put it on the vehicle to destination).
     */
    public function dispatch(Shipment $shipment, ?int $actorId = null): Shipment
    {
        return DB::transaction(function () use ($shipment, $actorId): Shipment {
            $shipment = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);

            if ($shipment->status !== CourierStatus::SORTED_FOR_TRANSFER) {
                throw ValidationException::withMessages([
                    'shipment' => ['Only a shipment sorted for transfer can be dispatched.'],
                ]);
            }

            $old = $shipment->status;
            $shipment->status = CourierStatus::IN_TRANSIT;
            $shipment->merchant_status = CourierStatus::merchantStatus(CourierStatus::IN_TRANSIT);

            // Parcel is en route: it is between branches now.
            if ($this->shipmentHasColumn('current_branch_id')) {
                $shipment->current_branch_id = null;
            }
            if ($this->shipmentHasColumn('current_sub_branch_id')) {
                $shipment->current_sub_branch_id = null;
            }

            $shipment->save();

            $this->track($shipment, $old, CourierStatus::IN_TRANSIT, 'Dispatched on transfer to destination branch.', $actorId);

            $fresh = $shipment->fresh();
            app(\Modules\Webhook\Services\WebhookService::class)
                ->queueShipmentEvent($fresh, 'shipment.in_transit');
            app(\Modules\Shipment\Services\ShipmentCallbackService::class)
                ->inTransit($fresh);

            return $fresh;
        });
    }

    /**
     * Bulk dispatch.
     *
     * @param  array<int>  $shipmentIds
     * @return array{dispatched: array<int>, skipped: array<int, string>}
     */
    public function bulkDispatch(array $shipmentIds, ?int $actorId = null): array
    {
        $dispatched = [];
        $skipped = [];

        foreach (array_unique($shipmentIds) as $id) {
            try {
                $shipment = Shipment::query()->find($id);
                if (! $shipment) {
                    $skipped[(int) $id] = 'Shipment not found.';
                    continue;
                }
                $this->dispatch($shipment, $actorId);
                $dispatched[] = (int) $id;
            } catch (\Throwable $e) {
                $skipped[(int) $id] = $e->getMessage();
            }
        }

        return ['dispatched' => $dispatched, 'skipped' => $skipped];
    }

    /**
     * Receive an in-transit transfer at the destination branch, then open a
     * pending last-mile delivery there.
     */
    public function receiveAtDestination(Shipment $shipment, ?int $actorId = null): Shipment
    {
        $received = DB::transaction(function () use ($shipment, $actorId): Shipment {
            $shipment = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);

            if ($shipment->status !== CourierStatus::IN_TRANSIT) {
                throw ValidationException::withMessages([
                    'shipment' => ['Only an in-transit shipment can be received at the destination branch.'],
                ]);
            }

            $old = $shipment->status;
            $shipment->status = CourierStatus::RECEIVED_AT_DESTINATION_BRANCH;
            $shipment->merchant_status = CourierStatus::merchantStatus(CourierStatus::RECEIVED_AT_DESTINATION_BRANCH);

            if ($this->shipmentHasColumn('current_branch_id')) {
                $shipment->current_branch_id = $shipment->destination_branch_id;
            }
            if ($this->shipmentHasColumn('current_sub_branch_id')) {
                $shipment->current_sub_branch_id = $shipment->destination_sub_branch_id;
            }

            $shipment->save();

            $this->track($shipment, $old, CourierStatus::RECEIVED_AT_DESTINATION_BRANCH, 'Received at destination branch.', $actorId);

            return $shipment->fresh();
        });

        // Notify the store the parcel reached its destination branch.
        app(\Modules\Shipment\Services\ShipmentCallbackService::class)
            ->receivedAtDestination($received);

        // At the destination the parcel is now a local last-mile job. Move it
        // to sorted_for_delivery and open a pending delivery so it lands on the
        // destination branch's deliveries board.
        return $this->openLocalDelivery($received, $actorId);
    }

    /**
     * Transition a just-received transfer into a last-mile delivery at the
     * destination branch.
     */
    private function openLocalDelivery(Shipment $shipment, ?int $actorId): Shipment
    {
        return DB::transaction(function () use ($shipment, $actorId): Shipment {
            $shipment = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);

            $old = $shipment->status;
            $shipment->status = CourierStatus::SORTED_FOR_DELIVERY;
            $shipment->merchant_status = CourierStatus::merchantStatus(CourierStatus::SORTED_FOR_DELIVERY);

            if ($this->shipmentHasColumn('sort_mode')) {
                $shipment->sort_mode = ShipmentSortingService::MODE_LAST_MILE;
            }
            if ($this->shipmentHasColumn('sorted_at')) {
                $shipment->sorted_at = now();
            }
            if ($actorId !== null && $this->shipmentHasColumn('sorted_by')) {
                $shipment->sorted_by = $actorId;
            }

            $shipment->save();

            $this->track($shipment, $old, CourierStatus::SORTED_FOR_DELIVERY, 'Sorted for last-mile delivery at destination branch.', $actorId);

            $fresh = $shipment->fresh();

            app(\Modules\Delivery\Services\DeliveryWorkflowService::class)
                ->createPendingForShipment($fresh, $actorId);

            app(\Modules\Webhook\Services\WebhookService::class)
                ->queueShipmentEvent($fresh, 'shipment.sorted_for_delivery');
            app(\Modules\Shipment\Services\ShipmentCallbackService::class)
                ->sortedForDelivery($fresh);

            return $fresh;
        });
    }

    private function track(Shipment $shipment, ?string $oldStatus, string $newStatus, string $description, ?int $createdBy): void
    {
        $schema = DB::getSchemaBuilder();
        if (! $schema->hasTable('tracking_events')) {
            return;
        }

        $columns = $schema->getColumnListing('tracking_events');

        $data = [
            'shipment_id' => $shipment->id,
            'tracking_number' => $shipment->tracking_number,
            'old_status' => $oldStatus,
            'status' => $newStatus,
            'merchant_status' => CourierStatus::merchantStatus($newStatus),
            'branch_id' => $shipment->current_branch_id ?? $shipment->destination_branch_id ?? $shipment->origin_branch_id,
            'sub_branch_id' => $shipment->current_sub_branch_id ?? $shipment->destination_sub_branch_id,
            'location_text' => null,
            'description' => $description,
            'visibility' => 'public',
            'created_by' => $createdBy,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('tracking_events')->insert(array_intersect_key($data, array_flip($columns)));
    }

    private function shipmentHasColumn(string $column): bool
    {
        return in_array($column, DB::getSchemaBuilder()->getColumnListing('shipments'), true);
    }
}
