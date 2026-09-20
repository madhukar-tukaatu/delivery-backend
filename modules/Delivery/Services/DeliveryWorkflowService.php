<?php

namespace Modules\Delivery\Services;

use Modules\Settlement\Services\SettlementWorkflowService;

use App\Events\DeliveryStatusUpdated;
use App\Events\ShipmentStatusUpdated;

use App\Models\User;
use App\Support\CourierStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Delivery\Models\DeliveryAssignment;
use Modules\POD\Services\PODWorkflowService;
use Modules\POD\Services\StoreManagerPaymentService;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Services\ShipmentCallbackService;
use Modules\Tracking\Services\TrackingService;
use Modules\Webhook\Services\WebhookService;

/**
 * Last-mile delivery workflow.
 *
 * Lifecycle:
 *   sorted_for_delivery  -> createPendingForShipment()  (status: pending, no rider)
 *   pending              -> assign()                    (status: assigned, rider set; shipment assigned_to_rider)
 *   assigned             -> accept()                    (status: accepted)
 *   accepted             -> outForDelivery()            (status: out_for_delivery)
 *   out_for_delivery     -> arrived()                   (arrived_at recorded)
 *   out_for_delivery     -> delivered()                 (arrival/POD proof gating; shipment delivered)
 *   (any active)         -> failed()                    (status: failed; shipment delivery_failed)
 */
class DeliveryWorkflowService
{
    public function __construct(
        private TrackingService $trackingService,
        private WebhookService $webhookService,
        private ShipmentCallbackService $callbacks,
        private StoreManagerPaymentService $paymentService,
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
            $this->callbacks->deliveryAssigned($shipment->fresh(), [
                'rider' => ['id' => $rider->id, 'name' => $rider->name, 'phone' => $rider->phone],
            ]);

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
            $this->webhookService->queueShipmentEvent($delivery->shipment->fresh(), 'delivery.accepted');
            $this->callbacks->deliveryAccepted($delivery->shipment->fresh(), [
                'rider' => ['id' => $user->id, 'name' => $user->name],
            ]);

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
            $this->callbacks->deliveryOutForDelivery($shipment->fresh(), [
                'rider' => ['id' => $user->id, 'name' => $user->name],
                'out_for_delivery_at' => $delivery->out_for_delivery_at?->toIso8601String(),
            ]);

            return $shipment->fresh();
        });
    }

    /**
     * Mark that the assigned rider has reached the delivery location.
     * The assignment remains out_for_delivery; arrived_at gates completion.
     */
    public function arrived(DeliveryAssignment $delivery, User $user): DeliveryAssignment
    {
        return DB::transaction(function () use ($delivery, $user) {
            $delivery = DeliveryAssignment::query()->lockForUpdate()->findOrFail($delivery->id);

            $this->ensureRider($delivery, $user);

            if ($delivery->status !== 'out_for_delivery') {
                throw ValidationException::withMessages([
                    'status' => ['Delivery must be out for delivery before arrival can be confirmed.'],
                ]);
            }

            if ($delivery->arrived_at) {
                return $delivery->fresh(['shipment', 'rider']);
            }

            $arrivedAt = now();
            $delivery->update([
                'arrived_at' => $arrivedAt,
            ]);

            $shipment = $delivery->shipment;
            $this->trackingService->record(
                $shipment->fresh(),
                CourierStatus::OUT_FOR_DELIVERY,
                'Rider arrived at the delivery location.',
                $user->id,
            );
            $this->webhookService->queueShipmentEvent($shipment->fresh(), 'delivery.arrived');
            $this->callbacks->deliveryArrived($shipment->fresh(), [
                'rider' => ['id' => $user->id, 'name' => $user->name],
                'arrived_at' => $arrivedAt->toIso8601String(),
            ]);

            return $delivery->fresh(['shipment', 'rider']);
        });
    }

    /**
     * Create the Store Manager payment session used for an online POD payment.
     * Only the assigned rider can initiate a session while out for delivery.
     */
    public function createPaymentSession(
        DeliveryAssignment $delivery,
        User $user,
        ?string $idempotencyKey = null,
    ): array {
        $delivery = DeliveryAssignment::query()->findOrFail($delivery->id);

        $this->ensureRider($delivery, $user);
        $this->ensurePaymentSessionStage($delivery);

        return $this->paymentService->createForShipment(
            $delivery->shipment,
            $idempotencyKey,
        );
    }

    /**
     * Return the current Store Manager payment session for a delivery.
     */
    public function paymentSession(
        DeliveryAssignment $delivery,
        User $user,
        bool $refresh = false,
    ): ?array {
        $delivery = DeliveryAssignment::query()->findOrFail($delivery->id);

        $this->ensureRider($delivery, $user);
        $this->ensurePaymentSessionStage($delivery);

        return $this->paymentService->currentForShipment(
            $delivery->shipment,
            $refresh,
        );
    }

    /**
     * Complete the delivery.
     *
     * A POD shipment is completed only after the customer pays the merchant
     * directly by cash or through a verified Store Manager payment session. The
     * platform does not collect or settle this payment.
     *
     * @param array{payment_method?: string, payment_session_id?: string, pod_collected_amount?: float, customer_confirmed?: bool, customer_name?: string, customer_signature?: string, remarks?: string} $data
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

            if (! $delivery->arrived_at) {
                throw ValidationException::withMessages([
                    'status' => ['Confirm that the rider has reached the delivery location before completing delivery.'],
                ]);
            }

            $shipment = $delivery->shipment()->with('merchant')->firstOrFail();
            $isPod = $this->isPod($shipment);
            $collectable = (float) ($shipment->total_collectable_amount
                ?: $shipment->total_collectable
                ?: $shipment->pod_amount);
            $directPayment = false;
            $cashCollected = false;
            $completionType = 'prepaid'; // prepaid | pod_cash | pod_online
            $paymentMethod = null;
            $paymentReference = null;
            $paymentSessionId = null;
            $receiptConfirmed = false;
            $customerName = null;
            $customerSignaturePath = null;
            $customerSignatureHash = null;
            $customerConfirmedAt = null;

            // Prepaid (or POD with nothing due): no collection at door.
            if (! $isPod || $collectable <= 0) {
                if (! empty($data['payment_method'])) {
                    throw ValidationException::withMessages([
                        'payment_method' => [
                            'This shipment is prepaid / not collectable. Do not submit a POD payment method.',
                        ],
                    ]);
                }
            }

            if ($isPod && $collectable > 0) {
                $paymentMethod = strtolower((string) ($data['payment_method'] ?? ''));

                if (! in_array($paymentMethod, ['cash', 'online'], true)) {
                    throw ValidationException::withMessages([
                        'payment_method' => [
                            'Choose cash or verified online payment before completing a pay-on-delivery order.',
                        ],
                    ]);
                }

                $collected = (float) ($data['pod_collected_amount'] ?? $collectable);

                if (abs($collected - $collectable) > 0.001) {
                    throw ValidationException::withMessages([
                        'pod_collected_amount' => [
                            'The payment amount must exactly match the amount due to the merchant.',
                        ],
                    ]);
                }

                $delivery->update(['pod_collected_amount' => $collected]);
                $podWorkflow = app(PODWorkflowService::class);

                if ($paymentMethod === 'online') {
                    $paymentSessionId = trim((string) ($data['payment_session_id'] ?? ''));

                    if ($paymentSessionId === '') {
                        throw ValidationException::withMessages([
                            'payment_session_id' => [
                                'A Store Manager payment session is required for online payment.',
                            ],
                        ]);
                    }

                    $verifiedSession = $this->paymentService->assertPaid(
                        $shipment,
                        $paymentSessionId,
                    );
                    $paymentReference = $verifiedSession->provider_reference ?: $paymentSessionId;
                    $directPayment = true;

                    $podWorkflow->markPaidDirectToMerchant(
                        $shipment,
                        null,
                        $collected,
                        'online',
                        $paymentReference,
                        $paymentSessionId,
                    );
                    $completionType = 'pod_online';
                } else {
                    // Cash stays with the rider until branch deposit, then settlement.
                    $cashCollected = true;
                    $completionType = 'pod_cash';
                    $podWorkflow->markCollectedForShipment($shipment, $user, $collected);
                }
            }

            $receiptConfirmed = $this->normalizeAcceptedFlag($data['customer_confirmed'] ?? false);
            $customerName = trim((string) ($data['customer_name'] ?? ''));
            $signatureData = trim((string) ($data['customer_signature'] ?? ''));

            if (! $receiptConfirmed) {
                throw ValidationException::withMessages([
                    'customer_confirmed' => [
                        'The receiver must confirm receipt before delivery can be completed.',
                    ],
                ]);
            }

            if ($customerName === '') {
                throw ValidationException::withMessages([
                    'customer_name' => ['Enter the name of the person receiving the parcel.'],
                ]);
            }

            if ($signatureData === '') {
                throw ValidationException::withMessages([
                    'customer_signature' => ['The receiver signature is required before delivery can be completed.'],
                ]);
            }

            [$customerSignaturePath, $customerSignatureHash] = $this->storeCustomerSignature(
                $delivery,
                $signatureData,
            );
            $customerConfirmedAt = now();

            $delivery->update([
                'status' => 'delivered',
                'delivered_at' => now(),
                'customer_confirmed_at' => $customerConfirmedAt,
                'customer_confirmed_name' => $customerName,
                'customer_signature_path' => $customerSignaturePath,
                'customer_signature_hash' => $customerSignatureHash,
                'remarks' => $data['remarks'] ?? $delivery->remarks,
            ]);

            $statusPair = app(SettlementWorkflowService::class)->settlementStatusAfterDelivery(
                $cashCollected,
                $directPayment,
                $shipment,
            );
            $podStatus = $statusPair['pod_status'];
            $settlementStatus = $statusPair['settlement_status'];

            $shipment->update([
                'status' => CourierStatus::DELIVERED,
                'merchant_status' => CourierStatus::merchantStatus(CourierStatus::DELIVERED),
                'delivered_at' => now(),
                'pod_status' => $podStatus,
                'settlement_status' => $settlementStatus,
            ]);

            $trackingDescription = $data['remarks']
                ?? match ($completionType) {
                    'pod_online' => 'Delivered after online POD was paid directly to the merchant.',
                    'pod_cash' => 'Delivered after the rider collected cash POD for later branch deposit and settlement.',
                    default => 'Delivered successfully (prepaid / no collection at door).',
                };

            $this->trackingService->record($shipment->fresh(), CourierStatus::DELIVERED, $trackingDescription, $user->id);
            $this->webhookService->queueShipmentEvent($shipment->fresh(), 'delivery.delivered');
            $freshDelivery = $delivery->fresh(['shipment', 'rider']);
            $freshShipment = $shipment->fresh();

            event(new DeliveryStatusUpdated($freshDelivery));
            event(new ShipmentStatusUpdated($freshShipment));

            // Auto settlement:
            // prepaid + pod_online -> list on settlements immediately
            // pod_cash -> wait for branch deposit (handled on deposit)
            try {
                if (in_array($completionType, ['prepaid', 'pod_online'], true)) {
                    app(SettlementWorkflowService::class)->autoEnsureForShipment(
                        $freshShipment,
                        $completionType,
                    );
                    $freshShipment = $freshShipment->fresh();
                }
            } catch (\Throwable $e) {
                report($e);
            }

            $this->callbacks->deliveryDelivered($freshShipment, [
                'completion_type' => $completionType,
                'payment_type' => $shipment->payment_type,
                'payment_method' => $paymentMethod,
                'payment_destination' => $directPayment ? 'merchant' : ($cashCollected ? 'rider' : null),
                'payment_reference' => $paymentReference,
                'payment_session_id' => $paymentSessionId,
                'payment_status' => $directPayment ? 'paid_direct' : ($cashCollected ? 'collected_pending_deposit' : 'not_required'),
                'pod_collected_amount' => ($directPayment || $cashCollected) ? $collectable : 0,
                'pod_status' => $podStatus,
                'settlement_status' => $settlementStatus,
                'arrived_at' => $delivery->arrived_at?->toIso8601String(),
                'receipt_confirmation' => [
                    'confirmed' => $receiptConfirmed,
                    'customer_name' => $customerName,
                    'confirmed_at' => $customerConfirmedAt?->toIso8601String(),
                    'signature_present' => filled($customerSignaturePath),
                    'signature_sha256' => $customerSignatureHash,
                ],
                'remarks' => $data['remarks'] ?? null,
            ]);

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
            $this->callbacks->deliveryFailed($shipment->fresh(), [
                'rider' => ['id' => $user->id, 'name' => $user->name],
                'reason' => $reason,
                'failed_at' => $delivery->failed_at?->toIso8601String(),
            ]);

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
     * Riders that can be assigned to this delivery.
     *
     * Prefers riders at the delivery's destination branch, but falls back to
     * all active riders/staff if none match — so the manager is never left
     * with an empty list.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function assignableRiders(Shipment $shipment, ?int $deliveryBranchId = null)
    {
        $scoped = $this->riderQuery($shipment, $deliveryBranchId)->limit(100)->get();

        if ($scoped->isNotEmpty()) {
            return $scoped;
        }

        // Fallback: any active rider/staff (branch fields may be unset or the
        // shipment routed to a node the rider isn't tagged to).
        return $this->baseRiderQuery()->limit(100)->get();
    }

    private function riderQuery(Shipment $shipment, ?int $deliveryBranchId = null)
    {
        $branchIds = array_values(array_filter([
            $deliveryBranchId,
            $shipment->destination_sub_branch_id,
            $shipment->destination_branch_id,
            $shipment->current_sub_branch_id,
            $shipment->current_branch_id,
        ]));

        return $this->baseRiderQuery()
            ->when(! empty($branchIds), fn ($q) => $q->whereIn('branch_id', $branchIds));
    }

    private function baseRiderQuery()
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', function ($q) {
                $q->whereIn('name', [
                    'rider',
                    'pickup_rider',
                    'delivery_staff',
                    'staff',
                    'sub_branch_manager',
                    'branch_manager',
                ]);
            })
            ->withCount(['assignedDeliveries as active_deliveries_count' => function ($q) {
                $q->whereIn('status', ['assigned', 'accepted', 'out_for_delivery']);
            }])
            ->orderBy('active_deliveries_count')
            ->orderBy('name');
    }

    private function isPod(Shipment $shipment): bool
    {
        $type = strtolower((string) $shipment->payment_type);

        return in_array($type, ['pod', 'cod', 'to_pay'], true);
    }

    private function normalizeAcceptedFlag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on', 'accepted'], true);
    }

    /**
     * Persist the customer signature as a private image and return its audit data.
     * Raw signature bytes are never included in merchant callbacks.
     *
     * @return array{0: string, 1: string}
     */
    private function storeCustomerSignature(DeliveryAssignment $delivery, string $signatureData): array
    {
        if (! preg_match('/^data:image\/(png|jpe?g);base64,(.+)$/s', $signatureData, $matches)) {
            throw ValidationException::withMessages([
                'customer_signature' => ['Provide a valid PNG or JPEG customer signature.'],
            ]);
        }

        $binary = base64_decode($matches[2], true);
        if ($binary === false || $binary === '' || strlen($binary) > 1_500_000) {
            throw ValidationException::withMessages([
                'customer_signature' => ['The customer signature is invalid or too large.'],
            ]);
        }

        $image = @getimagesizefromstring($binary);
        if (! is_array($image) || ! in_array($image['mime'] ?? null, ['image/png', 'image/jpeg'], true)) {
            throw ValidationException::withMessages([
                'customer_signature' => ['Provide a valid PNG or JPEG customer signature.'],
            ]);
        }

        $extension = strtolower($matches[1]) === 'png' ? 'png' : 'jpg';
        $path = sprintf(
            'delivery-signatures/%d/%d/%s.%s',
            $delivery->shipment_id,
            $delivery->id,
            Str::uuid()->toString(),
            $extension,
        );

        if (! Storage::disk('local')->put($path, $binary)) {
            throw ValidationException::withMessages([
                'customer_signature' => ['The customer signature could not be stored.'],
            ]);
        }

        return [$path, hash('sha256', $binary)];
    }

    private function ensurePaymentSessionStage(DeliveryAssignment $delivery): void
    {
        if ($delivery->status !== 'out_for_delivery') {
            throw ValidationException::withMessages([
                'status' => ['Payment sessions are available only while the delivery is out for delivery.'],
            ]);
        }

        if (! $delivery->arrived_at) {
            throw ValidationException::withMessages([
                'status' => ['The rider must confirm arrival before starting an online payment session.'],
            ]);
        }
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
