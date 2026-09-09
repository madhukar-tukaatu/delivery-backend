<?php

namespace Modules\Delivery\Services;

use App\Models\User;
use App\Support\CourierStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Delivery\Models\DeliveryAssignment;
use Modules\POD\Services\PODWorkflowService;
use Modules\Shipment\Models\Shipment;
use Modules\Tracking\Services\TrackingService;
use Modules\Webhook\Services\WebhookService;

/**
 * Last-mile delivery workflow.
 *
 * Lifecycle:
 *   sorted_for_delivery  -> createPendingForShipment()  (status: pending, no rider)
 *   pending              -> assign()                    (status: assigned, rider set; shipment assigned_to_rider)
 *   assigned             -> accept()                    (status: accepted)
 *   accepted             -> outForDelivery()            (status: out_for_delivery; shipment out_for_delivery)
 *   out_for_delivery     -> delivered()                 (status: delivered; POD gating; shipment delivered)
 *   (any active)         -> failed()                    (status: failed; shipment delivery_failed)
 */
class DeliveryWorkflowService
{
    public function __construct(
        private TrackingService $trackingService,
        private WebhookService $webhookService,
    ) {}

    /**
     * Create a pending (unassigned) delivery for a shipment that has just
     * been sorted for last-mile delivery. Idempotent.
     */
    public function createPendingForShipment(Shipment $shipment, ?int $actorId = null): DeliveryAssignment
    {
        return DB::transaction(function () use ($shipment, $actorId) {
            $existing = DeliveryAssignment::query()
                ->where('shipment_id', $shipment->id)
                ->whereNotIn('status', ['failed', 'cancelled'])
                ->first();

            if ($existing) {
                return $existing;
            }

            return DeliveryAssignment::create([
                'shipment_id' => $shipment->id,
                'branch_id' => $shipment->destination_branch_id ?? $shipment->current_branch_id,
                'sub_branch_id' => $shipment->destination_sub_branch_id ?? $shipment->current_sub_branch_id,
                'delivery_type' => 'last_mile',
                'status' => 'pending',
                'attempt_no' => 1,
                'assigned_by' => $actorId,
            ]);
        });
    }

    /**
     * Assign a rider to a pending delivery.
     */
    public function assign(DeliveryAssignment $delivery, User $rider, User $actor): DeliveryAssignment
    {
        return DB::transaction(function () use ($delivery, $rider, $actor) {
            $delivery = DeliveryAssignment::query()
                ->lockForUpdate()
                ->findOrFail($delivery->id);

            if (! in_array($delivery->status, ['pending', 'assigned'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['This delivery cannot be assigned at its current stage.'],
                ]);
            }

            $shipment = $delivery->shipment;

            $delivery->update([
                'rider_id' => $rider->id,
                'delivery_staff_id' => $rider->id,
                'status' => 'assigned',
                'assigned_at' => now(),
                'assigned_by' => $actor->id,
            ]);

            $shipment->update([
                'status' => CourierStatus::ASSIGNED_TO_RIDER,
                'merchant_status' => CourierStatus::merchantStatus(CourierStatus::ASSIGNED_TO_RIDER),
                'current_branch_id' => $shipment->destination_branch_id ?? $shipment->current_branch_id,
                'current_sub_branch_id' => $shipment->destination_sub_branch_id ?? $shipment->current_sub_branch_id,
            ]);

            $this->trackingService->record($shipment->fresh(), CourierStatus::ASSIGNED_TO_RIDER, 'Assigned to delivery rider ' . $rider->name . '.', $actor->id);
            $this->webhookService->queueShipmentEvent($shipment->fresh(), 'delivery.assigned');

            return $delivery->fresh(['shipment', 'rider']);
        });
    }

    /**
     * Assign multiple pending deliveries to a single rider.
     *
     * Ineligible deliveries (already delivered/failed/out-for-delivery) are
     * skipped and reported rather than failing the whole batch.
     *
     * @param  array<int>  $deliveryIds
     * @return array{assigned: array<int>, skipped: array<int, string>}
     */
    public function bulkAssign(array $deliveryIds, User $rider, User $actor): array
    {
        $assigned = [];
        $skipped = [];

        foreach (array_unique($deliveryIds) as $id) {
            try {
                $delivery = DeliveryAssignment::query()->find($id);

                if (! $delivery) {
                    $skipped[(int) $id] = 'Delivery not found.';
                    continue;
                }

                if (! in_array($delivery->status, ['pending', 'assigned'], true)) {
                    $skipped[(int) $id] = 'Not assignable (status: ' . $delivery->status . ').';
                    continue;
                }

                $this->assign($delivery, $rider, $actor);
                $assigned[] = (int) $id;
            } catch (\Throwable $e) {
                $skipped[(int) $id] = $e->getMessage();
            }
        }

        return [
            'assigned' => $assigned,
            'skipped' => $skipped,
        ];
    }

    public function accept(DeliveryAssignment $delivery, User $user): DeliveryAssignment
    {
        return DB::transaction(function () use ($delivery, $user) {
            $delivery = DeliveryAssignment::query()->lockForUpdate()->findOrFail($delivery->id);

            $this->ensureRider($delivery, $user);

            if ($delivery->status !== 'assigned') {
                throw ValidationException::withMessages([
                    'status' => ['Delivery must be assigned before it can be accepted.'],
                ]);
            }

            $delivery->update([
                'status' => 'accepted',
                'accepted_at' => now(),
            ]);

            $this->trackingService->record($delivery->shipment, CourierStatus::ASSIGNED_TO_RIDER, 'Rider accepted the delivery.', $user->id);

            return $delivery->fresh(['shipment', 'rider']);
        });
    }

    public function outForDelivery(DeliveryAssignment $delivery, User $user): Shipment
    {
        return DB::transaction(function () use ($delivery, $user) {
            $delivery = DeliveryAssignment::query()->lockForUpdate()->findOrFail($delivery->id);

            $this->ensureRider($delivery, $user);

            if (! in_array($delivery->status, ['assigned', 'accepted'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Delivery must be accepted before going out for delivery.'],
                ]);
            }

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

    /**
     * Complete the delivery.
     *
     * POD gating: a POD (pay-on-delivery) shipment can only be marked
     * delivered once payment is collected. For prepaid shipments there is
     * nothing to collect, so delivery completes directly.
     *
     * @param array{payment_method?: string, pod_collected_amount?: float, remarks?: string} $data
     */
    public function delivered(DeliveryAssignment $delivery, User $user, array $data = []): Shipment
    {
        return DB::transaction(function () use ($delivery, $user, $data) {
            $delivery = DeliveryAssignment::query()->lockForUpdate()->findOrFail($delivery->id);

            $this->ensureRider($delivery, $user);

            if ($delivery->status !== 'out_for_delivery') {
                throw ValidationException::withMessages([
                    'status' => ['Delivery must be out for delivery before it can be completed.'],
                ]);
            }

            $shipment = $delivery->shipment;

            $isPod = $this->isPod($shipment);
            $collectable = (float) ($shipment->total_collectable_amount ?: $shipment->pod_amount);

            if ($isPod && $collectable > 0) {
                $collected = (float) ($data['pod_collected_amount'] ?? $collectable);

                if ($collected + 0.001 < $collectable) {
                    throw ValidationException::withMessages([
                        'pod_collected_amount' => [
                            'Full payment must be collected before completing a pay-on-delivery order.',
                        ],
                    ]);
                }

                $delivery->update(['pod_collected_amount' => $collected]);

                app(PODWorkflowService::class)->markCollected($shipment, $user, $collected);
            }

            $delivery->update([
                'status' => 'delivered',
                'delivered_at' => now(),
                'remarks' => $data['remarks'] ?? $delivery->remarks,
            ]);

            $shipment->update([
                'status' => CourierStatus::DELIVERED,
                'merchant_status' => CourierStatus::merchantStatus(CourierStatus::DELIVERED),
                'delivered_at' => now(),
                'pod_status' => $isPod ? 'collected' : 'not_required',
                'settlement_status' => $isPod ? 'ready' : 'not_required',
            ]);

            $this->trackingService->record($shipment->fresh(), CourierStatus::DELIVERED, $data['remarks'] ?? 'Delivered successfully.', $user->id);
            $this->webhookService->queueShipmentEvent($shipment->fresh(), 'delivery.delivered');

            return $shipment->fresh();
        });
    }

    public function failed(DeliveryAssignment $delivery, User $user, string $reason): Shipment
    {
        return DB::transaction(function () use ($delivery, $user, $reason) {
            $delivery = DeliveryAssignment::query()->lockForUpdate()->findOrFail($delivery->id);

            $this->ensureRider($delivery, $user, allowManager: true);

            $shipment = $delivery->shipment;

            $delivery->update([
                'status' => 'failed',
                'failed_at' => now(),
                'failure_reason' => $reason,
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

    /**
     * Suggest a rider for a shipment's destination branch (least loaded).
     */
    public function suggestRider(Shipment $shipment): ?User
    {
        return $this->riderQuery($shipment)->first();
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function assignableRiders(Shipment $shipment)
    {
        return $this->riderQuery($shipment)->limit(50)->get();
    }

    private function riderQuery(Shipment $shipment)
    {
        $branchIds = array_values(array_filter([
            $shipment->destination_sub_branch_id,
            $shipment->destination_branch_id,
            $shipment->current_branch_id,
        ]));

        return User::query()
            ->where('is_active', true)
            ->when(! empty($branchIds), fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->whereHas('roles', function ($q) {
                $q->whereIn('name', ['rider', 'delivery_staff', 'sub_branch_manager', 'branch_manager']);
            })
            ->withCount(['deliveryAssignments as active_deliveries_count' => function ($q) {
                $q->whereIn('status', ['assigned', 'accepted', 'out_for_delivery']);
            }])
            ->orderBy('active_deliveries_count');
    }

    private function isPod(Shipment $shipment): bool
    {
        $type = strtolower((string) $shipment->payment_type);

        return in_array($type, ['pod', 'cod', 'to_pay'], true);
    }

    private function ensureRider(DeliveryAssignment $delivery, User $user, bool $allowManager = false): void
    {
        if ($user->isSuperAdmin() || $user->hasRole('main_admin')) {
            return;
        }

        if ($allowManager && ($user->hasRole('branch_manager') || $user->hasRole('sub_branch_manager'))) {
            return;
        }

        abort_unless((int) $delivery->rider_id === (int) $user->id, 403, 'Only the assigned rider can perform this action.');
    }
}
