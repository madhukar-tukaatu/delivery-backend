<?php

namespace Modules\Shipment\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Shipment\Models\Shipment;

class PickupWorkflowService
{
    public function createForShipment(Shipment $shipment): object
    {
        return Cache::lock("shipment:{$shipment->id}:pickup:create", 10)->block(3, function () use ($shipment) {
            $existing = DB::table('pickup_requests')->where('shipment_id', $shipment->id)->first();
            if ($existing) return $existing;

            $pickup = [
                'shipment_id' => $shipment->id,
                'merchant_id' => $shipment->merchant_id,
                'branch_id' => $shipment->origin_branch_id ?? $shipment->current_branch_id,
                'sub_branch_id' => $shipment->origin_sub_branch_id ?? $shipment->current_sub_branch_id,
                'pickup_location_id' => $shipment->pickup_location_id ?? null,
                'assigned_to' => null,
                'status' => 'pending',
                'pickup_address' => $shipment->pickup_address ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $id = DB::table('pickup_requests')->insertGetId($pickup);

            return DB::table('pickup_requests')->where('id', $id)->first();
        });
    }

    public function assign(int $shipmentId, int $staffId, int $actorId): object
    {
        $shipment = Shipment::findOrFail($shipmentId);
        $pickup = $this->createForShipment($shipment);

        DB::table('pickup_requests')->where('id', $pickup->id)->update([
            'assigned_to' => $staffId,
            'status' => 'assigned',
            'updated_at' => now(),
        ]);

        $this->track($shipmentId, $actorId, 'pickup_assigned', 'Pickup assigned', "Pickup assigned to staff #{$staffId}");

        return DB::table('pickup_requests')->where('id', $pickup->id)->first();
    }

    public function accept(int $pickupId, int $staffId): object
    {
        DB::table('pickup_requests')->where('id', $pickupId)->where('assigned_to', $staffId)->update([
            'status' => 'accepted',
            'updated_at' => now(),
        ]);

        return DB::table('pickup_requests')->where('id', $pickupId)->first();
    }

    public function pickedUp(int $pickupId, int $staffId, ?string $note = null): object
    {
        $pickup = DB::table('pickup_requests')->where('id', $pickupId)->first();
        abort_unless($pickup, 404, 'Pickup not found.');
        abort_unless((int) $pickup->assigned_to === (int) $staffId, 403, 'This pickup is not assigned to you.');

        DB::table('pickup_requests')->where('id', $pickupId)->update([
            'status' => 'picked_up',
            'picked_up_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('shipments')->where('id', $pickup->shipment_id)->update([
            'status' => 'picked_up',
            'updated_at' => now(),
        ]);

        $this->track($pickup->shipment_id, $staffId, 'picked_up', 'Parcel picked up', $note ?: 'Parcel picked up from merchant.');

        return DB::table('pickup_requests')->where('id', $pickupId)->first();
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
