<?php

namespace Modules\Delivery\Services;

use App\Models\User;
use App\Support\CourierStatus;
use Illuminate\Support\Facades\DB;
use Modules\COD\Services\CODWorkflowService;
use Modules\Delivery\Models\DeliveryAssignment;
use Modules\Shipment\Models\Shipment;
use Modules\Tracking\Services\TrackingService;
use Modules\Webhook\Services\WebhookService;

class DeliveryWorkflowService
{
    public function __construct(
        private TrackingService $trackingService,
        private WebhookService $webhookService,
    ) {}

    public function createDeliveryAssignment(Shipment $shipment): ?DeliveryAssignment
    {
        return DB::transaction(function () use ($shipment) {
            $existing = DeliveryAssignment::where('shipment_id', $shipment->id)->first();
            if ($existing) {
                return $existing;
            }

            $rider = $this->findRider($shipment);

            if (!$rider) {
                return null;
            }

            $delivery = DeliveryAssignment::create([
                'shipment_id' => $shipment->id,
                'rider_id' => $rider->id,
                'branch_id' => $shipment->destination_branch_id,
                'sub_branch_id' => $shipment->destination_sub_branch_id,
                'status' => 'assigned',
                'assigned_at' => now(),
            ]);

            $shipment->update([
                'status' => CourierStatus::ASSIGNED_TO_RIDER,
                'merchant_status' => CourierStatus::merchantStatus(CourierStatus::ASSIGNED_TO_RIDER),
                'current_branch_id' => $shipment->destination_branch_id,
                'current_sub_branch_id' => $shipment->destination_sub_branch_id,
            ]);

            $this->trackingService->record($shipment->fresh(), CourierStatus::ASSIGNED_TO_RIDER, 'Assigned to delivery rider.', $rider->id);
            $this->webhookService->queueShipmentEvent($shipment->fresh(), 'delivery.assigned');

            return $delivery;
        });
    }

    public function outForDelivery(DeliveryAssignment $delivery, User $user): Shipment
    {
        return DB::transaction(function () use ($delivery, $user) {
            abort_unless((int) $delivery->rider_id === (int) $user->id || $user->isSuperAdmin(), 403);

            $shipment = $delivery->shipment;

            $delivery->update([
                'status' => 'out_for_delivery',
                'out_for_delivery_at' => now(),
            ]);

            $shipment->update([
                'status' => CourierStatus::OUT_FOR_DELIVERY,
                'merchant_status' => CourierStatus::merchantStatus(CourierStatus::OUT_FOR_DELIVERY),
            ]);

            $this->trackingService->record($shipment->fresh(), CourierStatus::OUT_FOR_DELIVERY, 'Out for delivery.', $user->id);
            $this->webhookService->queueShipmentEvent($shipment->fresh(), 'delivery.out_for_delivery');

            return $shipment->fresh();
        });
    }

    public function delivered(DeliveryAssignment $delivery, User $user, array $data = []): Shipment
    {
        return DB::transaction(function () use ($delivery, $user, $data) {
            abort_unless((int) $delivery->rider_id === (int) $user->id || $user->isSuperAdmin(), 403);

            $shipment = $delivery->shipment;
            $codCollected = (float) ($data['cod_collected_amount'] ?? $shipment->total_collectable_amount ?? 0);

            $delivery->update([
                'status' => 'delivered',
                'delivered_at' => now(),
                'cod_collected_amount' => $codCollected,
                'remarks' => $data['remarks'] ?? null,
            ]);

            $shipment->update([
                'status' => CourierStatus::DELIVERED,
                'merchant_status' => CourierStatus::merchantStatus(CourierStatus::DELIVERED),
                'delivered_at' => now(),
                'cod_status' => $shipment->cod_amount > 0 ? 'collected' : 'not_required',
                'settlement_status' => $shipment->cod_amount > 0 ? 'ready' : 'not_required',
            ]);

            if ($shipment->cod_amount > 0) {
                app(CODWorkflowService::class)->markCollected($shipment, $user, $codCollected);
            }

            $this->trackingService->record($shipment->fresh(), CourierStatus::DELIVERED, $data['remarks'] ?? 'Delivered successfully.', $user->id);
            $this->webhookService->queueShipmentEvent($shipment->fresh(), 'delivery.delivered');

            return $shipment->fresh();
        });
    }

    public function failed(DeliveryAssignment $delivery, User $user, string $reason): Shipment
    {
        return DB::transaction(function () use ($delivery, $user, $reason) {
            abort_unless((int) $delivery->rider_id === (int) $user->id || $user->isSuperAdmin(), 403);

            $shipment = $delivery->shipment;

            $delivery->update([
                'status' => 'failed',
                'failed_at' => now(),
                'failed_reason' => $reason,
                'remarks' => $reason,
            ]);

            $shipment->update([
                'status' => CourierStatus::DELIVERY_FAILED,
                'merchant_status' => CourierStatus::merchantStatus(CourierStatus::DELIVERY_FAILED),
            ]);

            $this->trackingService->record($shipment->fresh(), CourierStatus::DELIVERY_FAILED, $reason, $user->id);
            $this->webhookService->queueShipmentEvent($shipment->fresh(), 'delivery.failed');

            return $shipment->fresh();
        });
    }

    private function findRider(Shipment $shipment): ?User
    {
        return User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($shipment) {
                $query->where('branch_id', $shipment->destination_sub_branch_id)
                    ->orWhere('branch_id', $shipment->destination_branch_id);
            })
            ->whereHas('roles', function ($query) {
                $query->whereIn('name', ['rider', 'sub_branch_manager', 'branch_manager']);
            })
            ->withCount(['deliveryAssignments as active_deliveries_count' => function ($query) {
                $query->whereIn('status', ['assigned', 'out_for_delivery']);
            }])
            ->orderBy('active_deliveries_count')
            ->first();
    }
}
