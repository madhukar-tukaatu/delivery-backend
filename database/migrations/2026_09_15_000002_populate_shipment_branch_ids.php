<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Shipment\Models\Shipment;

return new class extends Migration
{
    /**
     * Populate missing origin_branch_id and destination_branch_id for existing shipments.
     * 
     * For most cases where branch IDs are missing, we need to:
     * 1. Get the merchant's primary branch as origin
     * 2. Determine destination based on delivery address
     * 3. Set current_branch_id to origin (starting point)
     */
    public function up(): void
    {
        // Get all shipments with NULL branch IDs that are sorted or in transit
        $shipments = Shipment::query()
            ->where(function ($q) {
                $q->whereNull('origin_branch_id')
                    ->orWhereNull('destination_branch_id')
                    ->orWhereNull('current_branch_id');
            })
            ->where(function ($q) {
                $q->whereIn('status', [
                    'sorted_for_transfer',
                    'sorted_for_delivery',
                    'in_transit',
                    'received_at_destination_branch',
                    'picked_up',
                    'received_at_origin_branch',
                ])
                ->orWhereNotNull('merchant_id');
            })
            ->get();

        foreach ($shipments as $shipment) {
            $changes = [];

            // Set origin_branch_id from merchant's branch
            if (!$shipment->origin_branch_id && $shipment->merchant_id) {
                $merchantBranch = DB::table('merchants')
                    ->where('id', $shipment->merchant_id)
                    ->select('branch_id')
                    ->first();

                if ($merchantBranch?->branch_id) {
                    $shipment->origin_branch_id = $merchantBranch->branch_id;
                    $changes[] = 'origin_branch_id';
                } else {
                    // Default to branch 1 if merchant has no branch
                    $shipment->origin_branch_id = 1;
                    $changes[] = 'origin_branch_id (default)';
                }
            }

            // Set destination_branch_id - for now default to origin (local delivery)
            // In a real system, this would be determined by delivery address routing
            if (!$shipment->destination_branch_id) {
                $shipment->destination_branch_id = $shipment->origin_branch_id ?? 1;
                $changes[] = 'destination_branch_id';
            }

            // Set current_branch_id to origin (starting point)
            if (!$shipment->current_branch_id) {
                $shipment->current_branch_id = $shipment->origin_branch_id ?? 1;
                $changes[] = 'current_branch_id';
            }

            if (count($changes) > 0) {
                $shipment->save();
            }
        }
    }

    public function down(): void
    {
        // This is a data population migration, so down is a no-op
        // Don't erase the data we just populated
    }
};

