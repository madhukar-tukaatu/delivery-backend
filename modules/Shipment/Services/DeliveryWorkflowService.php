<?php

namespace Modules\Shipment\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Shipment\Models\Shipment;

class DeliveryWorkflowService
{
    public function assign(Shipment $shipment, int $riderId, int $actorId): object
    {
        return Cache::lock("shipment:{$shipment->id}:delivery:assign", 10)->block(3, function () use ($shipment, $riderId, $actorId) {
            $existing = DB::table('delivery_assignments')
                ->where('shipment_id', $shipment->id)
                ->whereIn('status', ['assigned', 'accepted', 'out_for_delivery'])
                ->first();

            if ($existing) return $existing;

            $deliveryId = DB::table('delivery_assignments')->insertGetId([
                'shipment_id' => $shipment->id,
                'rider_id' => $riderId,
                'branch_id' => $shipment->destination_branch_id,
                'sub_branch_id' => $shipment->destination_sub_branch_id,
                'status' => 'assigned',
                'assigned_by' => $actorId,
                'assigned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->track($shipment->id, $actorId, 'delivery_assigned', 'Delivery rider assigned', "Delivery assigned to rider #{$riderId}.");

            return DB::table('delivery_assignments')->where('id', $deliveryId)->first();
        });
    }

    public function accept(int $deliveryId, int $riderId): object
    {
        DB::table('delivery_assignments')->where('id', $deliveryId)->where('rider_id', $riderId)->update([
            'status' => 'accepted',
            'updated_at' => now(),
        ]);

        return DB::table('delivery_assignments')->where('id', $deliveryId)->first();
    }

    public function outForDelivery(int $deliveryId, int $riderId): object
    {
        $delivery = DB::table('delivery_assignments')->where('id', $deliveryId)->first();
        abort_unless($delivery, 404, 'Delivery not found.');
        abort_unless((int) $delivery->rider_id === (int) $riderId, 403, 'This delivery is not assigned to you.');

        DB::table('delivery_assignments')->where('id', $deliveryId)->update([
            'status' => 'out_for_delivery',
            'out_for_delivery_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('shipments')->where('id', $delivery->shipment_id)->update([
            'status' => 'out_for_delivery',
            'updated_at' => now(),
        ]);

        $this->track($delivery->shipment_id, $riderId, 'out_for_delivery', 'Out for delivery', 'Rider is out for delivery.');

        return DB::table('delivery_assignments')->where('id', $deliveryId)->first();
    }

    public function delivered(int $deliveryId, int $riderId, array $payload): object
    {
        $delivery = DB::table('delivery_assignments')->where('id', $deliveryId)->first();
        abort_unless($delivery, 404, 'Delivery not found.');
        abort_unless((int) $delivery->rider_id === (int) $riderId, 403, 'This delivery is not assigned to you.');

        $shipment = Shipment::findOrFail($delivery->shipment_id);

        DB::table('delivery_assignments')->where('id', $deliveryId)->update([
            'status' => 'delivered',
            'proof_type' => $payload['proof_type'] ?? null,
            'proof_value' => $payload['proof_value'] ?? null,
            'otp_verified' => (bool) ($payload['otp_verified'] ?? false),
            'delivered_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('shipments')->where('id', $shipment->id)->update([
            'status' => 'delivered',
            'delivered_at' => now(),
            'updated_at' => now(),
        ]);

        if ($shipment->payment_type === 'pod') {
            DB::table('pod_transactions')->where('shipment_id', $shipment->id)->update([
                'rider_id' => $riderId,
                'status' => 'collected_pending_deposit',
                'payment_method' => $payload['payment_method'] ?? 'cash',
                'collected_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->track($shipment->id, $riderId, 'delivered', 'Shipment delivered', 'Parcel delivered to customer.');

        return DB::table('delivery_assignments')->where('id', $deliveryId)->first();
    }

    public function failed(int $deliveryId, int $riderId, array $payload): object
    {
        $delivery = DB::table('delivery_assignments')->where('id', $deliveryId)->first();
        abort_unless($delivery, 404, 'Delivery not found.');
        abort_unless((int) $delivery->rider_id === (int) $riderId, 403, 'This delivery is not assigned to you.');

        $shipment = Shipment::findOrFail($delivery->shipment_id);
        $failedAttempts = ((int) ($shipment->failed_attempts ?? 0)) + 1;
        $limit = (int) config('delivery_operations.failed_attempt_limit', 3);
        $shipmentStatus = $failedAttempts >= $limit ? 'return_pending' : 'failed_delivery';

        DB::table('delivery_assignments')->where('id', $deliveryId)->update([
            'status' => 'failed',
            'failed_reason' => $payload['reason'] ?? 'Failed delivery',
            'failed_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('shipments')->where('id', $shipment->id)->update([
            'status' => $shipmentStatus,
            'failed_attempts' => $failedAttempts,
            'updated_at' => now(),
        ]);

        $this->track($shipment->id, $riderId, $shipmentStatus, 'Delivery failed', $payload['reason'] ?? 'Delivery attempt failed.');

        return DB::table('delivery_assignments')->where('id', $deliveryId)->first();
    }

    private function track(int $shipmentId, int $actorId, string $status, string $title, string $description): void
    {
        DB::table('shipment_tracking_events')->insert([
            'shipment_id' => $shipmentId,
            'actor_id' => $actorId,
            'status' => $status,
            'title' => $title,
            'description' => $description,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
