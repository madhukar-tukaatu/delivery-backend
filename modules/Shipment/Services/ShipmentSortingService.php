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
 * Runs after a shipment has been received at a branch hub (origin or transit).
 * Thin BM decision only — CURRENT operational branch vs FINAL destination:
 *
 *   - Same branch / coverage  => SORTED_FOR_DELIVERY (last mile here)
 *   - Different branch        => SORTED_FOR_TRANSFER (Transfers Outbound owns next hop)
 *
 * Does NOT walk multi-hop routes or invent next_hop = final when no route exists.
 * Optional hop_meta refresh runs only when transfer_route_id is already assigned.
 *
 * Accepts RECEIVED_AT_ORIGIN_BRANCH and RECEIVED_AT_TRANSIT_HUB so hop-by-hop
 * transfers can re-sort after each transit receive.
 */
final class ShipmentSortingService
{
    public const MODE_LAST_MILE = 'last_mile';

    public const MODE_TRANSFER = 'transfer';

    /**
     * Sort a single received shipment into its next leg.
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
                    ? 'Sorted for last-mile delivery at current branch.'
                    : 'Sorted for transfer. Next hop is resolved on Transfers Outbound.';

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

                // Thin sort: never invent next_hop = final when no route is assigned.
                $assignedRouteId = (int) ($shipment->transfer_route_id ?? 0);
                if (
                    $assignedRouteId <= 0
                    && $this->shipmentHasColumn('next_hop_branch_id')
                ) {
                    $shipment->next_hop_branch_id = null;
                }
                if ($mode === self::MODE_LAST_MILE
                    && $this->shipmentHasColumn('next_hop_branch_id')
                ) {
                    $shipment->next_hop_branch_id = null;
                }

                $shipment->save();

                // Optional light hop_meta refresh ONLY when a route is already assigned.
                // Sort eligibility must not depend on this; Transfers Outbound owns live next-hop.
                if ($assignedRouteId > 0) {
                    try {
                        $freshForProgress = $shipment->fresh();
                        app(\Modules\Shipment\Services\TransferRouteProgressService::class)
                            ->applyProgressToShipment(
                                $freshForProgress,
                                (int) ($freshForProgress->current_branch_id ?? 0) ?: null
                            );
                        $shipment = $shipment->fresh();
                    } catch (\Throwable $e) {
                        // Progress helper is additive; sorting must still succeed.
                    }
                }

                $this->recordTrackingEvent(
                    shipment: $shipment,
                    oldStatus: $oldStatus,
                    newStatus: $newStatus,
                    description: $note,
                    createdBy: $actorId
                );

                if ($mode === self::MODE_LAST_MILE) {
                    app(\Modules\Delivery\Services\DeliveryWorkflowService::class)
                        ->createPendingForShipment($shipment->fresh(), $actorId);
                }

                $fresh = $shipment->fresh();

                app(\Modules\Webhook\Services\WebhookService::class)
                    ->queueShipmentEvent(
                        $fresh,
                        $mode === self::MODE_LAST_MILE
                            ? 'shipment.sorted_for_delivery'
                            : 'shipment.sorted_for_transfer'
                    );

                $callbacks = app(\Modules\Shipment\Services\ShipmentCallbackService::class);
                if ($mode === self::MODE_LAST_MILE) {
                    $callbacks->sortedForDelivery($fresh);
                } else {
                    $callbacks->sortedForTransfer($fresh);
                }

                return $fresh;
            }
        );
    }

    /**
     * Classify last-mile vs transfer from CURRENT operational location vs FINAL
     * destination (not origin). Uses resolveOperationalBranchId / coverage
     * equality — does not require a multi-hop route match.
     * Falls back to origin when current is unset (pre-receive / legacy rows).
     */
    public function classify(Shipment $shipment): string
    {
        $currentNode =
            $shipment->current_sub_branch_id
            ?? $shipment->current_branch_id
            ?? $shipment->origin_sub_branch_id
            ?? $shipment->origin_branch_id;

        $destinationNode =
            $shipment->destination_sub_branch_id
            ?? $shipment->destination_branch_id;

        if ($currentNode === null || $destinationNode === null) {
            return self::MODE_LAST_MILE;
        }

        $progress = app(TransferRouteProgressService::class);
        if ($progress->sameOperationalLocation((int) $currentNode, (int) $destinationNode)) {
            return self::MODE_LAST_MILE;
        }

        return self::MODE_TRANSFER;
    }

    /**
     * Sortable after origin receive OR after transit-hub receive.
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

        $allowed = [
            CourierStatus::RECEIVED_AT_ORIGIN_BRANCH,
            CourierStatus::RECEIVED_AT_TRANSIT_HUB,
        ];

        if (! in_array($shipment->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'shipment' => [
                    'Shipment must be received at a branch hub before it can be sorted.',
                ],
            ]);
        }
    }

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
