<?php

declare(strict_types=1);

namespace Modules\Shipment\Services;

use App\Support\CourierStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Shipment\Models\Shipment;

/**
 * ShipmentSortingService
 * -----------------------
 *
 * Runs at the ORIGIN branch, immediately after a shipment has been received
 * (CourierStatus::RECEIVED_AT_ORIGIN_BRANCH). It decides where the shipment
 * goes next and moves it into the correct "ready" hand-off state:
 *
 *   - Same branch  (origin node == destination node)
 *         => SORTED_FOR_DELIVERY  (ready for last-mile delivery here)
 *
 *   - Other branch (origin node != destination node)
 *         => SORTED_FOR_TRANSFER  (ready to be added to a transfer batch)
 *
 * The "node" of a branch is its sub-branch id when present, otherwise the main
 * branch id. This mirrors BranchAssignmentService::buildRoute()'s
 * requires_transfer logic, which is the single source of truth for routing.
 *
 * The service does NOT create delivery assignments or transfer batches - those
 * belong to the delivery / transfer phases. It only records the sort decision
 * so the next phase can pick the shipment up cleanly.
 */
final class ShipmentSortingService
{
    public const MODE_LAST_MILE = 'last_mile';

    public const MODE_TRANSFER = 'transfer';

    /**
     * Sort a single received shipment into its next leg.
     *
     * @param  Shipment  $shipment  The shipment received at the origin branch.
     * @param  int|null  $actorId   The branch staff who performed the sort.
     */
    public function sort(
        Shipment $shipment,
        ?int $actorId = null
    ): Shipment {
        return DB::transaction(
            function () use ($shipment, $actorId): Shipment {
                $shipment = Shipment::query()
                    ->lockForUpdate()
                    ->findOrFail($shipment->id);

                $this->guardSortable($shipment);

                $mode = $this->classify($shipment);

                $newStatus = $mode === self::MODE_LAST_MILE
                    ? CourierStatus::SORTED_FOR_DELIVERY
                    : CourierStatus::SORTED_FOR_TRANSFER;

                $note = $mode === self::MODE_LAST_MILE
                    ? 'Sorted for last-mile delivery at origin branch.'
                    : 'Sorted for branch-to-branch transfer.';

                $oldStatus = $shipment->status;

                $shipment->status = $newStatus;
                $shipment->merchant_status =
                    CourierStatus::merchantStatus($newStatus);

                if ($this->shipmentHasColumn('sort_mode')) {
                    $shipment->sort_mode = $mode;
                }

                if ($this->shipmentHasColumn('sorted_at')) {
                    $shipment->sorted_at = now();
                }

                if (
                    $actorId !== null
                    && $this->shipmentHasColumn('sorted_by')
                ) {
                    $shipment->sorted_by = $actorId;
                }

                $shipment->save();

                $this->recordTrackingEvent(
                    shipment: $shipment,
                    oldStatus: $oldStatus,
                    newStatus: $newStatus,
                    description: $note,
                    createdBy: $actorId
                );

                /*
                |--------------------------------------------------------------------------
                | Last-mile: open a pending delivery so the branch manager can
                | immediately see it on the deliveries board and assign a rider.
                | Transfers are handled by the transfer/batch flow, not here.
                |--------------------------------------------------------------------------
                */
                if ($mode === self::MODE_LAST_MILE) {
                    app(\Modules\Delivery\Services\DeliveryWorkflowService::class)
                        ->createPendingForShipment($shipment->fresh(), $actorId);
                }

                /*
                |--------------------------------------------------------------------------
                | Notify the merchant that the parcel has been sorted and is now
                | either ready for last-mile delivery or queued for transfer.
                |--------------------------------------------------------------------------
                */
                app(\Modules\Webhook\Services\WebhookService::class)
                    ->queueShipmentEvent(
                        $shipment->fresh(),
                        $mode === self::MODE_LAST_MILE
                            ? 'shipment.sorted_for_delivery'
                            : 'shipment.sorted_for_transfer'
                    );

                return $shipment->fresh();
            }
        );
    }

    /**
     * Classify a shipment as last-mile (same branch) or transfer.
     *
     * node = sub_branch_id ?? branch_id.
     * Same origin/destination node => last mile, else transfer.
     */
    public function classify(Shipment $shipment): string
    {
        $originNode =
            $shipment->origin_sub_branch_id
            ?? $shipment->origin_branch_id;

        $destinationNode =
            $shipment->destination_sub_branch_id
            ?? $shipment->destination_branch_id;

        // If either side is unknown we cannot safely say a transfer is needed,
        // so default to local last-mile handling at the current branch.
        if ($originNode === null || $destinationNode === null) {
            return self::MODE_LAST_MILE;
        }

        return (int) $originNode === (int) $destinationNode
            ? self::MODE_LAST_MILE
            : self::MODE_TRANSFER;
    }

    /**
     * A shipment can only be sorted once it has been received at the origin
     * branch, and it must not already be sorted or closed.
     */
    private function guardSortable(Shipment $shipment): void
    {
        if (CourierStatus::isSorted($shipment->status)) {
            throw ValidationException::withMessages([
                'shipment' => [
                    'Shipment has already been sorted.',
                ],
            ]);
        }

        if (
            $shipment->status !==
            CourierStatus::RECEIVED_AT_ORIGIN_BRANCH
        ) {
            throw ValidationException::withMessages([
                'shipment' => [
                    'Shipment must be received at the origin branch before it can be sorted.',
                ],
            ]);
        }
    }

    /**
     * Write a public tracking event using only columns that exist.
     * Mirrors the schema-safe approach used across the pickup workflow.
     */
    private function recordTrackingEvent(
        Shipment $shipment,
        ?string $oldStatus,
        string $newStatus,
        string $description,
        ?int $createdBy
    ): void {
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
            'merchant_status' =>
                CourierStatus::merchantStatus($newStatus),
            'branch_id' =>
                $shipment->current_branch_id
                ?? $shipment->origin_branch_id,
            'sub_branch_id' =>
                $shipment->current_sub_branch_id
                ?? $shipment->origin_sub_branch_id,
            'location_text' => null,
            'description' => $description,
            'visibility' => 'public',
            'created_by' => $createdBy,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $data = array_intersect_key(
            $data,
            array_flip($columns)
        );

        DB::table('tracking_events')->insert($data);
    }

    private function shipmentHasColumn(string $column): bool
    {
        return in_array(
            $column,
            DB::getSchemaBuilder()
                ->getColumnListing('shipments'),
            true
        );
    }
}
