<?php

namespace Modules\Pickup\Services;

use App\Models\User;
use App\Support\CourierStatus;
use Illuminate\Support\Facades\DB;
use Modules\Pickup\Models\PickupRequest;
use Modules\Shipment\Models\Shipment;
use Modules\Tracking\Services\TrackingService;
use Modules\Webhook\Services\WebhookService;

class PickupWorkflowService
{
    public function __construct(
        private TrackingService $trackingService,
        private WebhookService $webhookService,
    ) {}

    public function createForShipment(Shipment $shipment): PickupRequest
    {
        return DB::transaction(function () use ($shipment) {
            $existing = PickupRequest::where('shipment_id', $shipment->id)->first();
            if ($existing) {
                return $existing;
            }

            $pickupStaff = $this->findPickupStaff($shipment);

            $pickup = PickupRequest::create([
                'shipment_id' => $shipment->id,
                'merchant_id' => $shipment->merchant_id,
                'branch_id' => $shipment->origin_branch_id,
                'sub_branch_id' => $shipment->origin_sub_branch_id,
                'pickup_name' => $shipment->sender_name,
                'pickup_phone' => $shipment->sender_phone,
                'pickup_address' => $shipment->sender_address,
                'pickup_city' => $shipment->sender_city,
                'pickup_area' => $shipment->sender_area,
                'pickup_lat' => $shipment->pickup_lat,
                'pickup_lng' => $shipment->pickup_lng,
                'assigned_to' => $pickupStaff?->id,
                'status' => $pickupStaff ? 'assigned' : 'pending',
                'requested_at' => now(),
                'assigned_at' => $pickupStaff ? now() : null,
            ]);

            $shipment->update([
                'status' => $pickupStaff ? CourierStatus::PICKUP_ASSIGNED : CourierStatus::BOOKED,
                'merchant_status' => CourierStatus::merchantStatus($pickupStaff ? CourierStatus::PICKUP_ASSIGNED : CourierStatus::BOOKED),
                'current_branch_id' => $shipment->origin_branch_id,
                'current_sub_branch_id' => $shipment->origin_sub_branch_id,
            ]);

            $this->trackingService->record(
                $shipment->fresh(),
                $pickupStaff ? CourierStatus::PICKUP_ASSIGNED : CourierStatus::BOOKED,
                $pickupStaff ? 'Pickup assigned.' : 'Pickup request created and waiting for assignment.',
                $pickupStaff?->id
            );

            $this->webhookService->queueShipmentEvent($shipment->fresh(), $pickupStaff ? 'pickup.assigned' : 'pickup.pending');

            return $pickup;
        });
    }

    public function markPickedUp(PickupRequest $pickup, User $user, ?string $remarks = null): Shipment
    {
        return DB::transaction(function () use ($pickup, $user, $remarks) {
            $shipment = $pickup->shipment;

            $pickup->update([
                'status' => 'picked_up',
                'picked_up_by' => $user->id,
                'picked_up_at' => now(),
                'remarks' => $remarks,
            ]);

            $shipment->update([
                'status' => CourierStatus::PICKED_UP,
                'merchant_status' => CourierStatus::merchantStatus(CourierStatus::PICKED_UP),
                'current_branch_id' => $shipment->origin_branch_id,
                'current_sub_branch_id' => $shipment->origin_sub_branch_id,
            ]);

            $firstStep = $shipment->routeSteps()->orderBy('sequence')->first();
            if ($firstStep && $firstStep->status === 'pending') {
                $firstStep->update(['status' => 'ready']);
            }

            $this->trackingService->record($shipment->fresh(), CourierStatus::PICKED_UP, $remarks ?: 'Parcel picked up from sender.', $user->id);
            $this->webhookService->queueShipmentEvent($shipment->fresh(), 'pickup.completed');

            return $shipment->fresh();
        });
    }

    public function markFailed(PickupRequest $pickup, User $user, string $reason): Shipment
    {
        return DB::transaction(function () use ($pickup, $user, $reason) {
            $shipment = $pickup->shipment;

            $pickup->update([
                'status' => 'failed',
                'failed_reason' => $reason,
                'failed_at' => now(),
                'remarks' => $reason,
            ]);

            $shipment->update([
                'status' => CourierStatus::PICKUP_FAILED,
                'merchant_status' => CourierStatus::merchantStatus(CourierStatus::PICKUP_FAILED),
            ]);

            $this->trackingService->record($shipment->fresh(), CourierStatus::PICKUP_FAILED, $reason, $user->id);
            $this->webhookService->queueShipmentEvent($shipment->fresh(), 'pickup.failed');

            return $shipment->fresh();
        });
    }

    private function findPickupStaff(Shipment $shipment): ?User
    {
        return User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($shipment) {
                $query->where('branch_id', $shipment->origin_sub_branch_id)
                    ->orWhere('branch_id', $shipment->origin_branch_id);
            })
            ->whereHas('roles', function ($query) {
                $query->whereIn('name', ['pickup_staff', 'sub_branch_manager', 'branch_manager']);
            })
            ->withCount(['assignedPickups as active_pickups_count' => function ($query) {
                $query->whereIn('status', ['pending', 'assigned']);
            }])
            ->orderBy('active_pickups_count')
            ->first();
    }
}
