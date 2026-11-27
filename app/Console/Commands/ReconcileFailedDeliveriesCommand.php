<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\CourierStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Delivery\Models\DeliveryAssignment;
use Modules\Delivery\Models\DeliveryRunItem;
use Modules\Shipment\Models\Shipment;

class ReconcileFailedDeliveriesCommand extends Command
{
    protected $signature = 'delivery:reconcile-failed {trackings?* : Optional specific tracking numbers to reset to failed}';

    protected $description = 'Reset and reconcile failed deliveries back to failed status with proper failure reasons and clean delivery assignments';

    public function handle(): int
    {
        $trackings = (array) $this->argument('trackings');
        if (empty($trackings)) {
            $trackings = ['TEX-20261126-245404', 'TEX-20261126-926633'];
        }

        $defaultReasons = [
            'TEX-20261126-245404' => 'Seller want to return',
            'TEX-20261126-926633' => 'Not enough time to deliver',
        ];

        $this->info("Reconciling " . count($trackings) . " shipment(s) to failed state...");

        foreach ($trackings as $tracking) {
            $shipment = Shipment::where('tracking_number', $tracking)->first();
            if (! $shipment) {
                $this->warn("Shipment [{$tracking}] not found, skipping.");
                continue;
            }

            $reason = $defaultReasons[$tracking] ?? 'Customer requested reschedule / delivery failed';

            DB::transaction(function () use ($shipment, $tracking, $reason) {
                // 1. Update Shipment
                $shipment->update([
                    'status' => CourierStatus::DELIVERY_FAILED,
                    'merchant_status' => CourierStatus::merchantStatus(CourierStatus::DELIVERY_FAILED),
                    'failed_attempts' => 1,
                    'max_delivery_attempts' => 3,
                ]);

                // 2. Clean up any extra pending / requeued assignments for this shipment
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

                    // Remove any newer duplicate/pending assignments
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

                // 3. Update delivery_run_items to failed status so run metrics match
                DeliveryRunItem::where('shipment_id', $shipment->id)->update([
                    'status' => DeliveryRunItem::STATUS_FAILED,
                    'reason' => $reason,
                    'failed_at' => now(),
                ]);
            });

            $this->info("✓ Reconciled [{$tracking}] to status 'failed' with reason: '{$reason}'.");
        }

        $this->info("Completed. These shipments will now show under the 'Failed' deliveries tab.");
        return 0;
    }
}
