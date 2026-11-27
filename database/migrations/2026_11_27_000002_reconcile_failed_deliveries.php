<?php

use App\Support\CourierStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Delivery\Models\DeliveryAssignment;
use Modules\Delivery\Models\DeliveryRunItem;
use Modules\Shipment\Models\Shipment;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $trackings = [
            'TEX-20261126-245404' => 'Seller want to return',
            'TEX-20261126-926633' => 'Not enough time to deliver',
        ];

        foreach ($trackings as $tracking => $reason) {
            $shipment = Shipment::where('tracking_number', $tracking)->first();
            if (! $shipment) {
                continue;
            }

            DB::transaction(function () use ($shipment, $reason) {
                $shipment->update([
                    'status' => CourierStatus::DELIVERY_FAILED,
                    'merchant_status' => CourierStatus::merchantStatus(CourierStatus::DELIVERY_FAILED),
                    'failed_attempts' => 1,
                    'max_delivery_attempts' => 3,
                    'failure_reason' => $reason,
                ]);

                $assignments = DeliveryAssignment::where('shipment_id', $shipment->id)
                    ->orderBy('id', 'asc')
                    ->get();

                if ($assignments->isNotEmpty()) {
                    $primary = $assignments->first();
                    $primary->update([
                        'status' => 'failed',
                        'failed_at' => now(),
                        'failure_reason' => $reason,
                        'remarks' => $reason,
                        'attempt_no' => 1,
                    ]);

                    $extras = $assignments->slice(1);
                    foreach ($extras as $extra) {
                        $extra->delete();
                    }
                } else {
                    DeliveryAssignment::create([
                        'shipment_id' => $shipment->id,
                        'branch_id' => $shipment->destination_branch_id ?? $shipment->current_branch_id,
                        'sub_branch_id' => $shipment->destination_sub_branch_id ?? $shipment->current_sub_branch_id,
                        'delivery_type' => 'last_mile',
                        'status' => 'failed',
                        'failed_at' => now(),
                        'failure_reason' => $reason,
                        'remarks' => $reason,
                        'attempt_no' => 1,
                    ]);
                }

                DeliveryRunItem::where('shipment_id', $shipment->id)->update([
                    'status' => DeliveryRunItem::STATUS_FAILED,
                    'reason' => $reason,
                    'failed_at' => now(),
                ]);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // One-time reconciliation migration
    }
};
