<?php

declare(strict_types=1);

namespace Modules\Pickup\Services;

use App\Models\User;
use App\Support\CourierStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Pickup\Models\PickupRequest;
use Modules\Pickup\Models\PickupRequestShipment;
use Modules\Pickup\Support\PickupCustody;
use Modules\Pickup\Support\PickupShipmentStatus;
use Modules\Pickup\Support\PickupStatus;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Services\ShipmentSortingService;

final class PickupRequestService
{
    public function __construct(
        private readonly PickupCallbackService $callbacks,
        private readonly ShipmentSortingService $sorting,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Get
    |--------------------------------------------------------------------------
    */

    public function get(
        PickupRequest $pickup
    ): PickupRequest {
        return $pickup->load([
            'merchant',
            'branch',
            'subBranch',
            'pickupBranch',
            'pickupSubBranch',
            'pickupLocation',
            'assignedStaff',
            'assignedBy',
            'pickedUpBy',
            'shipments',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Attach shipment manually
    |--------------------------------------------------------------------------
    */

    public function attachShipment(
        Shipment $shipment,
        int $userId,
        ?string $remarks = null
    ): PickupRequestShipment {
        return DB::transaction(
            function () use (
                $shipment,
                $userId,
                $remarks
            ): PickupRequestShipment {
                $pickup = PickupRequest::query()
                    ->where(
                        'merchant_id',
                        $shipment->merchant_id
                    )
                    ->where(
                        'pickup_location_id',
                        $shipment->pickup_location_id
                    )
                    ->whereIn(
                        'status',
                        PickupStatus::acceptingShipments()
                    )
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                if (! $pickup) {
                    throw ValidationException::withMessages([
                        'pickup' => [
                            'No open pickup request exists for this shipment.',
                        ],
                    ]);
                }

                if (
                    $shipment->status ===
                    CourierStatus::CANCELLED
                ) {
                    throw ValidationException::withMessages([
                        'shipment' => [
                            'Cancelled shipment cannot be attached to a pickup.',
                        ],
                    ]);
                }

                if (
                    ! in_array(
                        $shipment->status,
                        [
                            CourierStatus::AWAITING_PICKUP,
                            CourierStatus::PICKUP_ASSIGNED,
                        ],
                        true
                    )
                ) {
                    throw ValidationException::withMessages([
                        'shipment' => [
                            'Shipment is not eligible for pickup attachment.',
                        ],
                    ]);
                }

                return $this->attachToPickup(
                    pickup: $pickup,
                    shipment: $shipment,
                    remarks: $remarks
                );
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Assign rider
    |--------------------------------------------------------------------------
    */

    public function assign(
        PickupRequest $pickup,
        User $staff,
        User $assignedBy
    ): PickupRequest {
        $fresh = DB::transaction(
            function () use (
                $pickup,
                $staff,
                $assignedBy
            ): PickupRequest {
                $pickup = PickupRequest::query()
                    ->lockForUpdate()
                    ->findOrFail($pickup->id);

                if (
                    ! in_array(
                        $pickup->status,
                        [
                            PickupStatus::REQUESTED,
                            PickupStatus::ASSIGNED,
                        ],
                        true
                    )
                ) {
                    throw ValidationException::withMessages([
                        'status' => [
                            'This pickup cannot be assigned at its current stage.',
                        ],
                    ]);
                }

                $this->validateStaffForPickup(
                    pickup: $pickup,
                    staff: $staff
                );

                $oldStaffId = $pickup->assigned_to;

                $pickup->assigned_to = $staff->id;
                $pickup->assigned_by = $assignedBy->id;
                $pickup->assigned_at = now();
                $pickup->status = PickupStatus::ASSIGNED;

                if (
                    $this->pickupHasColumn('accepted_at')
                    && $pickup->accepted_at === null
                ) {
                    $pickup->accepted_at = now();
                }

                $pickup->save();

                /*
                |--------------------------------------------------------------------------
                | Update shipments
                |--------------------------------------------------------------------------
                */

                $shipments = $pickup
                    ->activeShipments()
                    ->lockForUpdate()
                    ->get();

                foreach ($shipments as $shipment) {
                    if (! $shipment) {
                        continue;
                    }

                    if (
                        $shipment->status ===
                        CourierStatus::CANCELLED
                    ) {
                        continue;
                    }

                    if (
                        $shipment->status ===
                        CourierStatus::AWAITING_PICKUP
                    ) {
                        $this->changeShipmentStatus(
                            shipment: $shipment,
                            status: CourierStatus::PICKUP_ASSIGNED,
                            userId: $assignedBy->id,
                            note: $oldStaffId
                                ? 'Pickup rider reassigned.'
                                : 'Pickup rider assigned.'
                        );
                    }
                }

                $this->createPickupEvent(
                    pickup: $pickup,
                    type: $oldStaffId
                        ? 'rider_reassigned'
                        : 'rider_assigned',
                    description: $oldStaffId
                        ? 'Pickup rider reassigned.'
                        : 'Pickup rider assigned.'
                );

                return $this->get($pickup);
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Fire the callback AFTER the transaction has fully committed.
        |
        | Dispatching from inside the DB::transaction closure relied on
        | ->afterCommit(), which can silently skip queuing. Doing it here,
        | outside the transaction, matches the (working) resend path and
        | guarantees the job is queued.
        |--------------------------------------------------------------------------
        */
        $this->callbacks->riderAssigned($fresh);

        return $fresh;
    }

    /*
    |--------------------------------------------------------------------------
    | Transfer
    |--------------------------------------------------------------------------
    */

    public function transfer(
        PickupRequest $pickup,
        User $newStaff,
        User $transferredBy,
        string $reason
    ): PickupRequest {
        return DB::transaction(
            function () use (
                $pickup,
                $newStaff,
                $transferredBy,
                $reason
            ): PickupRequest {
                $pickup = PickupRequest::query()
                    ->lockForUpdate()
                    ->findOrFail($pickup->id);

                if (
                    ! in_array(
                        $pickup->status,
                        [
                            PickupStatus::REQUESTED,
                            PickupStatus::ASSIGNED,
                            PickupStatus::STARTED,
                        ],
                        true
                    )
                ) {
                    throw ValidationException::withMessages([
                        'status' => [
                            'This pickup cannot be transferred at its current stage.',
                        ],
                    ]);
                }

                $this->validateStaffForPickup(
                    pickup: $pickup,
                    staff: $newStaff
                );

                $oldStaffId = $pickup->assigned_to;

                if (
                    $oldStaffId !== null
                    && (int) $oldStaffId === (int) $newStaff->id
                ) {
                    throw ValidationException::withMessages([
                        'staff_id' => [
                            'This rider is already assigned to this pickup.',
                        ],
                    ]);
                }

                $pickup->assigned_to = $newStaff->id;
                $pickup->assigned_by = $transferredBy->id;
                $pickup->assigned_at = now();

                /*
                 * Do not reset STARTED.
                 */
                $pickup->save();

                $this->createPickupEvent(
                    pickup: $pickup,
                    type: 'transferred',
                    description: $reason
                );

                $shipments = $pickup
                    ->activeShipments()
                    ->lockForUpdate()
                    ->get();

                foreach ($shipments as $shipment) {
                    if (! $shipment) {
                        continue;
                    }

                    if (
                        $shipment->status ===
                        CourierStatus::PICKUP_ASSIGNED
                    ) {
                        $this->createShipmentTrackingEvent(
                            shipment: $shipment,
                            oldStatus: CourierStatus::PICKUP_ASSIGNED,
                            newStatus: CourierStatus::PICKUP_ASSIGNED,
                            description: 'Pickup transferred to another rider.',
                            createdBy: $transferredBy->id
                        );
                    }
                }

                return $this->get($pickup);
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Accept
    |--------------------------------------------------------------------------
    */

    public function accept(
        PickupRequest $pickup,
        User $user
    ): PickupRequest {
        $fresh = DB::transaction(
            function () use (
                $pickup,
                $user
            ): PickupRequest {
                $pickup = PickupRequest::query()
                    ->lockForUpdate()
                    ->findOrFail($pickup->id);

                $this->ensureAssignedRider(
                    pickup: $pickup,
                    user: $user
                );

                if (
                    $pickup->status !==
                    PickupStatus::ASSIGNED
                ) {
                    throw ValidationException::withMessages([
                        'status' => [
                            'Pickup cannot be accepted. Current status: "' . $pickup->status . '". '
                            . 'Pickup must be ASSIGNED before it can be accepted. '
                            . 'Was it assigned to you by admin?',
                        ],
                    ]);
                }

                $pickup->status = PickupStatus::ACCEPTED;

                if (
                    $this->pickupHasColumn('accepted_at')
                    && $pickup->accepted_at === null
                ) {
                    $pickup->accepted_at = now();
                }

                $pickup->save();

                $this->createPickupEvent(
                    pickup: $pickup,
                    type: 'accepted',
                    description: 'Rider accepted the pickup assignment.'
                );

                return $this->get($pickup);
            }
        );

        $this->callbacks->riderAccepted($fresh);

        return $fresh;
    }

    /*
    |--------------------------------------------------------------------------
    | Start
    |--------------------------------------------------------------------------
    */

    public function start(
        PickupRequest $pickup,
        User $user
    ): PickupRequest {
        $fresh = DB::transaction(
            function () use (
                $pickup,
                $user
            ): PickupRequest {
                $pickup = PickupRequest::query()
                    ->lockForUpdate()
                    ->findOrFail($pickup->id);

                $this->ensureAssignedRider(
                    pickup: $pickup,
                    user: $user
                );

                if (
                    $pickup->status !==
                    PickupStatus::ACCEPTED
                ) {
                    throw ValidationException::withMessages([
                        'status' => [
                            'Pickup cannot be started. Current status: "' . $pickup->status . '". '
                            . 'Pickup must be ACCEPTED before it can be started. '
                            . 'Did you call /accept first?',
                        ],
                    ]);
                }

                $pickup->status = PickupStatus::STARTED;

                if (
                    $this->pickupHasColumn('started_at')
                    && $pickup->started_at === null
                ) {
                    $pickup->started_at = now();
                }

                $pickup->save();

                $this->createPickupEvent(
                    pickup: $pickup,
                    type: 'started',
                    description: 'Rider started travelling to pickup location.'
                );

                return $this->get($pickup);
            }
        );

        $this->callbacks->riderStarted($fresh);

        return $fresh;
    }

    /*
    |--------------------------------------------------------------------------
    | Arrive
    |--------------------------------------------------------------------------
    */

    public function arrive(
        PickupRequest $pickup,
        User $user
    ): PickupRequest {
        $fresh = DB::transaction(
            function () use (
                $pickup,
                $user
            ): PickupRequest {
                $pickup = PickupRequest::query()
                    ->lockForUpdate()
                    ->findOrFail($pickup->id);

                $this->ensureAssignedRider(
                    pickup: $pickup,
                    user: $user
                );

                if (
                    $pickup->status !==
                    PickupStatus::STARTED
                ) {
                    throw ValidationException::withMessages([
                        'status' => [
                            'Pickup must be started before rider arrival.',
                        ],
                    ]);
                }

                $pickup->status = PickupStatus::ARRIVED;
                $pickup->arrived_at = now();
                $pickup->save();

                $this->createPickupEvent(
                    pickup: $pickup,
                    type: 'arrived',
                    description: 'Rider arrived at pickup location.'
                );

                return $this->get($pickup);
            }
        );

        $this->callbacks->riderArrived($fresh);

        return $fresh;
    }

    /*
    |--------------------------------------------------------------------------
    | Collect shipment
    |--------------------------------------------------------------------------
    */

    public function collectShipment(
        PickupRequest $pickup,
        Shipment $shipment,
        User $user,
        ?string $remarks = null
    ): PickupRequestShipment {
        $result = DB::transaction(
            function () use (
                $pickup,
                $shipment,
                $user,
                $remarks
            ): PickupRequestShipment {
                $pickup = PickupRequest::query()
                    ->lockForUpdate()
                    ->findOrFail($pickup->id);

                $this->ensureAssignedRider(
                    pickup: $pickup,
                    user: $user
                );

                if (
                    $pickup->status !==
                    PickupStatus::ARRIVED
                ) {
                    throw ValidationException::withMessages([
                        'status' => [
                            'Rider must arrive before collecting shipments.',
                        ],
                    ]);
                }

                $item = PickupRequestShipment::query()
                    ->where(
                        'pickup_request_id',
                        $pickup->id
                    )
                    ->where(
                        'shipment_id',
                        $shipment->id
                    )
                    ->whereNull('removed_at')
                    ->lockForUpdate()
                    ->first();

                if (! $item) {
                    throw ValidationException::withMessages([
                        'shipment' => [
                            'Shipment does not belong to this pickup request.',
                        ],
                    ]);
                }

                if (
                    $shipment->status ===
                    CourierStatus::CANCELLED
                ) {
                    throw ValidationException::withMessages([
                        'shipment' => [
                            'Cancelled shipment cannot be collected.',
                        ],
                    ]);
                }

                if (
                    ! in_array(
                        $shipment->status,
                        [
                            CourierStatus::PICKUP_ASSIGNED,
                            CourierStatus::AWAITING_PICKUP,
                        ],
                        true
                    )
                ) {
                    throw ValidationException::withMessages([
                        'shipment' => [
                            'Shipment is not ready for collection.',
                        ],
                    ]);
                }

                $this->changeShipmentStatus(
                    shipment: $shipment,
                    status: CourierStatus::PICKED_UP,
                    userId: $user->id,
                    note: 'Shipment collected by rider.'
                );

                if ($remarks !== null) {
                    $item->remarks = $remarks;
                    $item->save();
                }

                $pickup->picked_up_at =
                    $pickup->picked_up_at ?? now();

                $pickup->picked_up_by = $user->id;

                /*
                |--------------------------------------------------------------------------
                | Check if all shipments are now collected. If yes, transition
                | pickup status to COLLECTED.
                |--------------------------------------------------------------------------
                */
                $pending = $pickup
                    ->activeShipments()
                    ->get()
                    ->filter(
                        static function (
                            $s
                        ): bool {
                            if (! $s) {
                                return false;
                            }

                            return ! in_array(
                                $s->status,
                                [
                                    CourierStatus::PICKED_UP,
                                    CourierStatus::CANCELLED,
                                ],
                                true
                            );
                        }
                    );

                if ($pending->isEmpty()) {
                    $pickup->status = PickupStatus::COLLECTED;
                }

                $pickup->save();

                return $item->fresh([
                    'pickupRequest',
                    'shipment',
                ]);
            }
        );

        \Illuminate\Support\Facades\Log::warning('collectShipment completed', [
            'pickup_id' => $result->pickupRequest?->id,
            'shipment_id' => $result->shipment?->id,
            'shipment_status' => $result->shipment?->status,
        ]);

        $this->callbacks->shipmentCollected(
            pickup: $result->pickupRequest,
            shipment: $result->shipment
        );

        /*
        |--------------------------------------------------------------------------
        | When this collection completes the pickup (all shipments collected),
        | fire the pickup-level pickup.collected callback once.
        |--------------------------------------------------------------------------
        */
        if (
            $result->pickupRequest
            && $result->pickupRequest->status === PickupStatus::COLLECTED
        ) {
            $this->callbacks->pickupCollected($result->pickupRequest);
        }

        try {
            if ($result->pickupRequest) {
                app(\Modules\Pickup\Services\PickupRunService::class)->syncFromPickupRequest($result->pickupRequest);
            }
        } catch (\Throwable) {
        }

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | Receive shipment at origin
    |--------------------------------------------------------------------------
    */

    public function receiveShipment(
        PickupRequest $pickup,
        Shipment $shipment,
        User $staff,
        array $weightData = []
    ): PickupRequestShipment {
        $synced = false;

        $result = DB::transaction(
            function () use (
                $pickup,
                $shipment,
                $staff,
                $weightData,
                &$synced
            ): PickupRequestShipment {
                $pickup = PickupRequest::query()
                    ->lockForUpdate()
                    ->findOrFail($pickup->id);

                $item = PickupRequestShipment::query()
                    ->where(
                        'pickup_request_id',
                        $pickup->id
                    )
                    ->where(
                        'shipment_id',
                        $shipment->id
                    )
                    ->whereNull('removed_at')
                    ->lockForUpdate()
                    ->first();

                if (! $item) {
                    throw ValidationException::withMessages([
                        'shipment' => [
                            'Shipment does not belong to this pickup request.',
                        ],
                    ]);
                }

                $shipment = Shipment::query()
                    ->lockForUpdate()
                    ->findOrFail($shipment->id);

                /*
                |--------------------------------------------------------------------------
                | Decide from where the parcel REALLY is (not only its status)
                |--------------------------------------------------------------------------
                |
                |  - picked up (with rider)        -> normal receive below
                |  - "in transit" but no TR moved it -> status was wrong: put it back
                |                                     to picked_up, then receive
                |  - already accepted / left on a TR / moved on -> close this pickup
                |                                     line as verified, parcel untouched
                */
                $custody = PickupCustody::describe(
                    $shipment,
                    (string) $item->status
                );

                if (
                    $item->status === PickupShipmentStatus::RECEIVED
                ) {
                    throw ValidationException::withMessages([
                        'shipment' => [
                            'Shipment is already received on this pickup.',
                        ],
                    ]);
                }

                if ($custody['needs_sync']) {
                    $this->syncResolvedItem(
                        item: $item,
                        shipment: $shipment,
                        custody: $custody,
                        staffId: (int) $staff->id
                    );

                    $synced = true;

                    return $item->fresh([
                        'pickupRequest',
                        'shipment',
                    ]);
                }

                if (
                    $custody['state'] === 'wrong_in_transit'
                ) {
                    $this->resetWrongInTransit(
                        shipment: $shipment,
                        pickup: $pickup,
                        actorId: (int) $staff->id
                    );
                } elseif (
                    $shipment->status !==
                    CourierStatus::PICKED_UP
                ) {
                    throw ValidationException::withMessages([
                        'shipment' => [
                            sprintf(
                                'Shipment cannot be received: %s.',
                                $custody['label']
                            ),
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Move shipment to origin branch
                |--------------------------------------------------------------------------
                */

                if (
                    $this->shipmentHasColumn(
                        'current_branch_id'
                    )
                ) {
                    $shipment->current_branch_id =
                        $shipment->origin_branch_id;
                }

                if (
                    $this->shipmentHasColumn(
                        'current_sub_branch_id'
                    )
                ) {
                    $shipment->current_sub_branch_id =
                        $shipment->origin_sub_branch_id;
                }

                /*
                |--------------------------------------------------------------------------
                | Weight & Dimensions Intake Check (Actual vs Volumetric)
                |--------------------------------------------------------------------------
                */
                $actualWeight = isset($weightData['actual_weight']) && (float) $weightData['actual_weight'] > 0
                    ? (float) $weightData['actual_weight']
                    : (isset($weightData['weight']) && (float) $weightData['weight'] > 0 ? (float) $weightData['weight'] : null);

                $lengthCm = isset($weightData['length_cm']) && (float) $weightData['length_cm'] > 0
                    ? (float) $weightData['length_cm']
                    : ($this->shipmentHasColumn('length_cm') && $shipment->length_cm ? (float) $shipment->length_cm : null);

                $widthCm = isset($weightData['width_cm']) && (float) $weightData['width_cm'] > 0
                    ? (float) $weightData['width_cm']
                    : ($this->shipmentHasColumn('width_cm') && $shipment->width_cm ? (float) $shipment->width_cm : null);

                $heightCm = isset($weightData['height_cm']) && (float) $weightData['height_cm'] > 0
                    ? (float) $weightData['height_cm']
                    : ($this->shipmentHasColumn('height_cm') && $shipment->height_cm ? (float) $shipment->height_cm : null);

                $volumetricDivisor = 5000;
                try {
                    $volumetricDivisor = max(1, (float) (DB::table('rate_settings')->value('volumetric_divisor') ?? 5000));
                } catch (\Throwable $e) {}

                $volumetricWeight = null;
                if ($lengthCm !== null && $widthCm !== null && $heightCm !== null) {
                    $volumetricWeight = round(($lengthCm * $widthCm * $heightCm) / $volumetricDivisor, 2);
                }

                $effectiveActual = $actualWeight ?? (float) ($shipment->actual_weight ?: $shipment->weight ?: 1.5);
                $chargeableWeight = max($effectiveActual, (float) ($volumetricWeight ?? 0));

                if ($actualWeight !== null || $volumetricWeight !== null || !empty($weightData)) {
                    $shipment->weight = $chargeableWeight;
                    if ($this->shipmentHasColumn('actual_weight')) {
                        $shipment->actual_weight = $effectiveActual;
                    }
                    if ($this->shipmentHasColumn('volumetric_weight')) {
                        $shipment->volumetric_weight = $volumetricWeight ?? 0;
                    }
                    if ($this->shipmentHasColumn('chargeable_weight')) {
                        $shipment->chargeable_weight = $chargeableWeight;
                    }
                    if ($this->shipmentHasColumn('length_cm') && $lengthCm !== null) {
                        $shipment->length_cm = $lengthCm;
                    }
                    if ($this->shipmentHasColumn('width_cm') && $widthCm !== null) {
                        $shipment->width_cm = $widthCm;
                    }
                    if ($this->shipmentHasColumn('height_cm') && $heightCm !== null) {
                        $shipment->height_cm = $heightCm;
                    }

                    try {
                        $resolver = app(\Modules\Shipment\Services\CheckoutDeliveryChargeResolver::class);
                        $resolved = $resolver->resolveFromShipment($shipment);
                        $newFare = round((float) ($resolved['delivery_charge'] ?? $shipment->delivery_charge), 2);
                        $shipment->delivery_charge = $newFare;
                        if (!empty($resolved['breakdown'])) {
                            $shipment->delivery_charge_breakdown = $resolved['breakdown'];
                        }

                        $paidBy = strtolower((string) ($shipment->delivery_charge_paid_by ?? 'customer'));
                        $podAmount = (float) ($shipment->pod_amount ?? 0);
                        if ($paidBy === 'customer') {
                            $shipment->total_collectable_amount = round($podAmount + $newFare, 2);
                        } else {
                            $shipment->total_collectable_amount = round($podAmount, 2);
                        }

                        // Maintain route_distance_km and route details (road/flight lane + last mile)
                        if ($this->shipmentHasColumn('route_distance_km')) {
                            $assigner = app(\Modules\Shipment\Services\BranchAssignmentService::class);
                            $routeDetails = $assigner->buildRoute(
                                ['branch_id' => $shipment->origin_branch_id, 'sub_branch_id' => $shipment->origin_sub_branch_id],
                                ['branch_id' => $shipment->destination_branch_id, 'sub_branch_id' => $shipment->destination_sub_branch_id],
                                $shipment->delivery_lat !== null ? (float) $shipment->delivery_lat : null,
                                $shipment->delivery_lng !== null ? (float) $shipment->delivery_lng : null,
                                (string) ($shipment->service_type ?? 'standard')
                            );
                            $shipment->route_distance_km = $routeDetails['total_distance_km'] ?? $routeDetails['distance_km'] ?? 0;
                            if ($this->shipmentHasColumn('transfer_route_id') && !empty($routeDetails['transfer_route_id']) && empty($shipment->transfer_route_id)) {
                                $shipment->transfer_route_id = $routeDetails['transfer_route_id'];
                            }
                            if ($this->shipmentHasColumn('route_id') && !empty($routeDetails['route_id']) && empty($shipment->route_id)) {
                                $shipment->route_id = $routeDetails['route_id'];
                            }
                            if ($this->shipmentHasColumn('path_text') && !empty($routeDetails['path_text']) && empty($shipment->path_text)) {
                                $shipment->path_text = $routeDetails['path_text'];
                            }
                        }

                        if ($this->shipmentHasColumn('last_mile_fee') && !empty($resolved['breakdown'])) {
                            $shipment->last_mile_fee = round((float) data_get($resolved, 'breakdown.breakdown.extra_delivery_distance.total', 0), 2);
                        }

                        // Sync downstream pricing breakdown if table exists
                        if (\Illuminate\Support\Facades\Schema::hasTable('shipment_price_breakdowns')) {
                            \Illuminate\Support\Facades\DB::table('shipment_price_breakdowns')
                                ->where('shipment_id', $shipment->id)
                                ->update([
                                    'final_price' => $shipment->delivery_charge,
                                    'snapshot_json' => json_encode($shipment->delivery_charge_breakdown),
                                    'updated_at' => now(),
                                ]);
                        }

                        // Sync invoices if already created
                        $invoices = \Modules\Billing\Models\Invoice::query()->where('shipment_id', $shipment->id)->get();
                        if ($invoices->isNotEmpty()) {
                            $split = \Modules\Settlement\Services\SettlementWorkflowService::splitDeliveryCharge(
                                (float) $shipment->delivery_charge,
                                (string) ($shipment->delivery_free_by ?? 'none'),
                                (string) ($shipment->delivery_charge_paid_by ?? 'customer')
                            );
                            foreach ($invoices as $inv) {
                                $payerType = strtolower((string) ($inv->payer_type ?? 'merchant'));
                                $share = $payerType === 'company' ? $split['marketplace_share'] : $split['store_share'];
                                $podFee = (float) ($shipment->pod_charge ?? 0);
                                if ($payerType === 'company') {
                                    $podFee = 0.0;
                                }
                                $invTotal = round($share + $podFee, 2);
                                $inv->subtotal = $invTotal;
                                $inv->total_amount = $invTotal;
                                if (\Illuminate\Support\Facades\Schema::hasColumn('invoices', 'outstanding_amount')) {
                                    $inv->outstanding_amount = max(0.0, round($invTotal - (float) ($inv->paid_amount ?? 0), 2));
                                }
                                $inv->save();
                            }
                        }
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('PickupRequestService: could not recalculate fare on receive: ' . $e->getMessage());
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Update status if supported
                |--------------------------------------------------------------------------
                */

                if (
                    defined(
                        CourierStatus::class .
                        '::RECEIVED_AT_ORIGIN_BRANCH'
                    )
                ) {
                    $this->changeShipmentStatus(
                        shipment: $shipment,
                        status: CourierStatus::RECEIVED_AT_ORIGIN_BRANCH,
                        userId: $staff->id,
                        note: 'Shipment received at origin branch.'
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | Sort the received shipment
                    |--------------------------------------------------------------------------
                    |
                    | As soon as the branch receives a shipment it is sorted into
                    | its next leg:
                    |   - same branch  => SORTED_FOR_DELIVERY (last-mile)
                    |   - other branch => SORTED_FOR_TRANSFER
                    |
                    | Done inside the same transaction so a received shipment is
                    | never left un-sorted.
                    |--------------------------------------------------------------------------
                    */
                    $shipment = $this->sorting->sort(
                        shipment: $shipment,
                        actorId: $staff->id
                    );

                    /*
                    |--------------------------------------------------------------------------
                    | Stamp the pivot as verified/received by the branch.
                    |--------------------------------------------------------------------------
                    */
                    $this->markPivotReceived(
                        item: $item,
                        staffId: $staff->id
                    );
                } else {
                    $shipment->save();

                    $this->createShipmentTrackingEvent(
                        shipment: $shipment,
                        oldStatus: CourierStatus::PICKED_UP,
                        newStatus: CourierStatus::PICKED_UP,
                        description: 'Shipment received at origin branch.',
                        createdBy: $staff->id
                    );
                }

                if (
                    $this->pickupHasColumn(
                        'received_at_origin_at'
                    )
                ) {
                    $pickup->received_at_origin_at = now();
                    $pickup->save();
                }

                return $item->fresh([
                    'pickupRequest',
                    'shipment',
                ]);
            }
        );

        if (! $synced) {
            $this->callbacks->shipmentReceivedAtOrigin(
                pickup: $result->pickupRequest,
                shipment: $result->shipment
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Complete the pickup when EVERY shipment has been resolved at the
        | origin branch (received/sorted OR rejected).
        |--------------------------------------------------------------------------
        */
        $completed = $this->finalizePickupIfResolved(
            $result->pickupRequest
        );

        if ($completed) {
            $result->setRelation('pickupRequest', $completed);
        }

        try {
            if ($result->pickupRequest) {
                app(\Modules\Pickup\Services\PickupRunService::class)->syncFromPickupRequest($result->pickupRequest);
            }
        } catch (\Throwable) {
        }

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | Reject shipment at origin (branch verification discrepancy)
    |
    | The branch manager could not accept a collected shipment: it is missing,
    | damaged, or does not match the manifest. The shipment is pulled out of the
    | forward flow and flagged for follow-up, while the rest of the pickup can
    | still be received and completed.
    |--------------------------------------------------------------------------
    */

    public function rejectShipment(
        PickupRequest $pickup,
        Shipment $shipment,
        User $staff,
        string $reason,
        string $type = 'other'
    ): PickupRequestShipment {
        $result = DB::transaction(
            function () use (
                $pickup,
                $shipment,
                $staff,
                $reason,
                $type
            ): PickupRequestShipment {
                $pickup = PickupRequest::query()
                    ->lockForUpdate()
                    ->findOrFail($pickup->id);

                $item = PickupRequestShipment::query()
                    ->where(
                        'pickup_request_id',
                        $pickup->id
                    )
                    ->where(
                        'shipment_id',
                        $shipment->id
                    )
                    ->whereNull('removed_at')
                    ->lockForUpdate()
                    ->first();

                if (! $item) {
                    throw ValidationException::withMessages([
                        'shipment' => [
                            'Shipment does not belong to this pickup request.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Only a shipment that reached the branch (picked up but not yet
                | received/sorted) can be rejected during verification.
                |--------------------------------------------------------------------------
                */
                $custody = PickupCustody::describe(
                    $shipment,
                    (string) $item->status
                );

                if (! $custody['can_reject']) {
                    throw ValidationException::withMessages([
                        'shipment' => [
                            sprintf(
                                'Only a picked-up shipment can be rejected at the branch (%s).',
                                $custody['label']
                            ),
                        ],
                    ]);
                }

                $note = sprintf(
                    'Rejected at origin branch (%s): %s',
                    $type,
                    $reason
                );

                $this->changeShipmentStatus(
                    shipment: $shipment,
                    status: CourierStatus::PICKUP_FAILED,
                    userId: $staff->id,
                    note: $note
                );

                $this->markPivotRejected(
                    item: $item,
                    staffId: $staff->id,
                    reason: $reason,
                    type: $type
                );

                return $item->fresh([
                    'pickupRequest',
                    'shipment',
                ]);
            }
        );

        $completed = $this->finalizePickupIfResolved(
            $result->pickupRequest
        );

        if ($completed) {
            $result->setRelation('pickupRequest', $completed);
        }

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | Complete the pickup once every shipment is resolved
    |
    | A shipment is resolved when it has been received/sorted at the branch, or
    | rejected (pickup_failed), or cancelled. The pickup stays in
    | ON_WAY_TO_BRANCH until the last shipment is resolved; only then does it
    | transition to COMPLETED and fire pickup.completed (once, deduplicated).
    |
    | Returns the completed PickupRequest, or null if not yet complete.
    |--------------------------------------------------------------------------
    */

    private function finalizePickupIfResolved(
        ?PickupRequest $pickup
    ): ?PickupRequest {
        /*
        | The branch can verify parcels as soon as the rider has collected them,
        | even if the rider never pressed "start transit" in the app.
        */
        if (
            ! $pickup
            || ! in_array(
                $pickup->status,
                [
                    PickupStatus::COLLECTED,
                    PickupStatus::ON_WAY_TO_BRANCH,
                ],
                true
            )
        ) {
            return null;
        }

        $pickupRequest = PickupRequest::query()
            ->with([
                'shipments' => fn ($q) => $q->wherePivotNull('removed_at'),
            ])
            ->find($pickup->id);

        if (! $pickupRequest || $pickupRequest->shipments->isEmpty()) {
            return null;
        }

        $unresolved = $pickupRequest->shipments
            ->filter(function (Shipment $s): bool {
                // The pickup line itself was resolved (received / rejected).
                if (
                    PickupShipmentStatus::isResolved(
                        (string) ($s->pivot?->status ?? '')
                    )
                ) {
                    return false;
                }

                return ! in_array(
                    $s->status,
                    [
                        CourierStatus::RECEIVED_AT_ORIGIN_BRANCH,
                        CourierStatus::SORTED_FOR_DELIVERY,
                        CourierStatus::SORTED_FOR_TRANSFER,
                        CourierStatus::PICKUP_FAILED,
                        CourierStatus::CANCELLED,
                    ],
                    true
                );
            });

        if ($unresolved->isNotEmpty()) {
            return null;
        }

        // Guard against a duplicate pickup.completed callback.
        // pickup_callback_logs has no migration yet; treat a missing table as "not sent".
        $completedCallbackSent = \Illuminate\Support\Facades\Schema::hasTable('pickup_callback_logs')
            && \Modules\Pickup\Models\PickupCallbackLog::query()
            ->where('pickup_request_id', $pickupRequest->id)
            ->where('event', 'pickup.completed')
            ->whereIn('status', ['delivered', 'pending', 'queued'])
            ->exists();

        $pickupRequest->status = PickupStatus::COMPLETED;

        if ($this->pickupHasColumn('completed_at')) {
            $pickupRequest->completed_at = now();
        }

        /*
        |--------------------------------------------------------------------------
        | Bulk pickup discount (store-configurable)
        |
        | If the store has configured a bulk discount and this pickup's received
        | packet count meets the threshold, record the flat discount on the
        | pickup. Settlement subtracts it from the store's delivery-charge bill.
        | Counted once per pickup, locked in at completion.
        |--------------------------------------------------------------------------
        */
        $this->applyBulkPickupDiscount(
            $pickupRequest,
            $pickupRequest->shipments
        );

        $pickupRequest->save();

        if (! $completedCallbackSent) {
            $this->callbacks->pickupCompleted($pickupRequest);
        }

        try {
            app(\Modules\Pickup\Services\PickupRunService::class)->syncFromPickupRequest($pickupRequest);
        } catch (\Throwable) {
        }

        return $pickupRequest;
    }

    /*
    |--------------------------------------------------------------------------
    | Bulk pickup discount
    |--------------------------------------------------------------------------
    |
    | Reads the store's configured threshold + amount and, if the number of
    | successfully received (non-rejected) shipments in this pickup meets the
    | threshold, sets pickup.delivery_discount to the flat amount.
    |--------------------------------------------------------------------------
    */
    private function applyBulkPickupDiscount(
        PickupRequest $pickup,
        $shipments
    ): void {
        if (! $this->pickupHasColumn('delivery_discount')) {
            return;
        }

        $merchant = $pickup->merchant
            ?? ($pickup->merchant_id
                ? \Modules\Merchant\Models\Merchant::query()->find($pickup->merchant_id)
                : null);

        if (! $merchant) {
            return;
        }

        $threshold = (int) ($merchant->bulk_pickup_discount_threshold ?? 0);
        $amount = (float) ($merchant->bulk_pickup_discount_amount ?? 0);

        if ($threshold <= 0 || $amount <= 0) {
            return;
        }

        // Count only shipments that actually arrived at the branch.
        $receivedCount = $shipments
            ->filter(function (Shipment $s): bool {
                return in_array(
                    $s->status,
                    [
                        CourierStatus::RECEIVED_AT_ORIGIN_BRANCH,
                        CourierStatus::SORTED_FOR_DELIVERY,
                        CourierStatus::SORTED_FOR_TRANSFER,
                    ],
                    true
                );
            })
            ->count();

        $pickup->delivery_discount = $receivedCount >= $threshold
            ? round($amount, 2)
            : 0;
    }

    /*
    |--------------------------------------------------------------------------
    | Pivot stamps (schema-safe)
    |--------------------------------------------------------------------------
    */

    private function markPivotReceived(
        PickupRequestShipment $item,
        int $staffId
    ): void {
        $item->status = PickupShipmentStatus::RECEIVED;

        if ($this->pivotHasColumn('collection_status')) {
            $item->collection_status = PickupShipmentStatus::RECEIVED;
        }

        if ($this->pivotHasColumn('collected_by')) {
            $item->collected_by = $item->collected_by ?? $staffId;
        }

        if (
            $this->pivotHasColumn('collected_at')
            && $item->collected_at === null
        ) {
            $item->collected_at = now();
        }

        $item->save();
    }

    /*
    |--------------------------------------------------------------------------
    | Pickup custody: real per-parcel state + repair
    |--------------------------------------------------------------------------
    |
    | Parcels can leave the pickup flow without the branch ever pressing Receive
    | (before the fix, Transfers outbound listed picked-up parcels and a TR could
    | carry them). Then the pickup waits forever: shipments are not picked_up, so
    | Receive fails, and the pickup never completes. reconcile() lines the pickup
    | up with reality. Used by the admin "Fix pickup status" button and by
    | `php artisan pickups:reconcile`.
    |--------------------------------------------------------------------------
    */

    /**
     * Put a `pickup_state` (PickupCustody::describe) on every shipment of the
     * pickup so screens show where each parcel really is.
     */
    public function attachCustody(
        PickupRequest $pickup
    ): PickupRequest {
        if (! $pickup->relationLoaded('shipments')) {
            $pickup->load('shipments');
        }

        $pickup->shipments->each(
            function (Shipment $s): void {
                $s->setAttribute(
                    'pickup_state',
                    PickupCustody::describe(
                        $s,
                        (string) ($s->pivot?->status ?? '')
                    )
                );
            }
        );

        return $pickup;
    }

    /**
     * Preview (apply=false) or apply the custody repair for one pickup.
     *
     * Per unresolved pickup line:
     *  - left_on_tr / moved_on / accepted : mark the line verified (parcel untouched)
     *  - rejected/cancelled parcel        : mark the line rejected
     *  - wrong_in_transit (no TR)         : status back to picked_up so the branch
     *                                       can Receive it (or receive now when
     *                                       $receiveCorrected is true)
     *  - with_rider but loaded on an OPEN
     *    (not dispatched) TR              : take it off that TR
     * Then the pickup completes when every line is resolved.
     *
     * @return array<string,mixed>
     */
    public function reconcile(
        PickupRequest $pickup,
        ?int $actorId,
        bool $apply = false,
        bool $receiveCorrected = false,
        string $source = 'admin'
    ): array {
        $run = function () use ($pickup, $actorId, $apply, $receiveCorrected): array {
            $pickup = PickupRequest::query()
                ->when($apply, fn ($q) => $q->lockForUpdate())
                ->findOrFail($pickup->id);

            $items = PickupRequestShipment::query()
                ->where('pickup_request_id', $pickup->id)
                ->whereNull('removed_at')
                ->with('shipment')
                ->orderBy('id')
                ->get();

            $rows = [];
            $changes = 0;
            $toReceive = [];

            foreach ($items as $item) {
                $shipment = $item->shipment;
                if (! $shipment) {
                    continue;
                }
                $custody = PickupCustody::describe($shipment, (string) $item->status);
                $action = null;
                $actionLabel = null;

                $openTr = null;
                if ($custody['state'] === 'with_rider' || $custody['state'] === 'wrong_in_transit') {
                    $openTr = DB::table('dispatch_manifest_items as dmi')
                        ->join('dispatch_manifests as dm', 'dm.id', '=', 'dmi.dispatch_manifest_id')
                        ->where('dmi.shipment_id', $shipment->id)
                        ->where('dm.status', 'open')
                        ->where('dmi.status', 'added')
                        ->first(['dmi.id as item_id', 'dm.id as manifest_id']);
                }

                if (! PickupShipmentStatus::isResolved((string) $item->status) && $custody['needs_sync']) {
                    $action = $custody['state'] === 'rejected' ? 'mark_rejected' : 'mark_verified';
                    $actionLabel = $action === 'mark_rejected'
                        ? 'Close pickup line as rejected (parcel is '.str_replace('_', ' ', (string) $shipment->status).')'
                        : 'Mark verified on pickup (parcel already '.($custody['tr'] ? 'left on '.$custody['tr']['number'] : str_replace('_', ' ', (string) $shipment->status)).'; parcel not changed)';
                } elseif ($custody['state'] === 'wrong_in_transit') {
                    $action = 'reset_status';
                    $actionLabel = $receiveCorrected
                        ? 'Status in transit -> picked up (no TR carried it), then receive at branch'
                        : 'Status in transit -> picked up (no TR carried it); then press Receive';
                } elseif ($openTr) {
                    $action = 'remove_from_open_tr';
                    $actionLabel = 'Take off open TR (not received at branch yet)';
                }

                $rows[] = [
                    'shipment_id' => (int) $shipment->id,
                    'tracking_number' => $shipment->tracking_number,
                    'shipment_status' => (string) $shipment->status,
                    'item_status' => (string) $item->status,
                    'state' => $custody['state'],
                    'label' => $custody['label'],
                    'tr' => $custody['tr'],
                    'action' => $action,
                    'action_label' => $actionLabel,
                ];

                if (! $action) {
                    continue;
                }
                $changes++;
                if (! $apply) {
                    continue;
                }

                if ($action === 'mark_verified' || $action === 'mark_rejected') {
                    $this->syncResolvedItem($item, $shipment, $custody, (int) ($actorId ?? 0));
                } elseif ($action === 'reset_status') {
                    $this->resetWrongInTransit($shipment, $pickup, $actorId);
                    if ($openTr) {
                        $this->dropFromOpenTr((int) $openTr->item_id, (int) $openTr->manifest_id, $pickup);
                    }
                    if ($receiveCorrected) {
                        $toReceive[] = (int) $shipment->id;
                    }
                } elseif ($action === 'remove_from_open_tr') {
                    $this->dropFromOpenTr((int) $openTr->item_id, (int) $openTr->manifest_id, $pickup);
                }
            }

            return [$pickup, $rows, $changes, $toReceive];
        };

        $before = PickupRequest::query()->findOrFail($pickup->id);
        [$p, $rows, $changes, $toReceive] = $apply ? DB::transaction($run) : $run();

        $received = [];
        $errors = [];
        if ($apply) {
            foreach ($toReceive as $shipmentId) {
                try {
                    $actor = $actorId ? User::query()->find($actorId) : null;
                    if (! $actor) {
                        throw new \RuntimeException('Receive needs a user.');
                    }
                    $this->receiveShipment($p, Shipment::query()->findOrFail($shipmentId), $actor);
                    $received[] = $shipmentId;
                } catch (\Throwable $e) {
                    $errors[] = ['shipment_id' => $shipmentId, 'error' => $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage()];
                }
            }
            $this->finalizePickupIfResolved(PickupRequest::query()->find($p->id));
        }

        $after = PickupRequest::query()->findOrFail($pickup->id);

        $report = [
            'pickup_id' => (int) $after->id,
            'request_number' => $after->request_number,
            'applied' => $apply,
            'status_before' => (string) $before->status,
            'status_after' => (string) $after->status,
            'changes' => $changes,
            'received' => $received,
            'errors' => $errors,
            'items' => $rows,
        ];

        if ($apply && $changes > 0) {
            $this->auditReconcile($after, $actorId, $report, $source);
        }

        return $report;
    }

    /**
     * Close a pickup line whose parcel is already past the branch (or dead)
     * without changing the parcel itself.
     */
    private function syncResolvedItem(
        PickupRequestShipment $item,
        Shipment $shipment,
        array $custody,
        int $staffId
    ): void {
        $where = $custody['tr']
            ? sprintf('already left on %s', $custody['tr']['number'])
            : sprintf('already %s', str_replace('_', ' ', (string) $shipment->status));

        if ($custody['state'] === 'rejected') {
            $this->markPivotRejected(
                $item,
                $staffId,
                'Closed on pickup: parcel is '.str_replace('_', ' ', (string) $shipment->status).'.',
                'other'
            );

            return;
        }

        $this->markPivotReceived($item, $staffId);

        $note = sprintf('Pickup verification closed: parcel was %s before the branch verified it.', $where);
        $item->remarks = trim(((string) $item->remarks).' '.$note);
        $item->save();

        $this->createShipmentTrackingEvent(
            shipment: $shipment,
            oldStatus: (string) $shipment->status,
            newStatus: (string) $shipment->status,
            description: $note,
            createdBy: $staffId ?: null
        );
    }

    /**
     * The parcel says "in transit" but no dispatched TR carried it: put it
     * back to picked_up (it is still part of the pickup, at / on the way to
     * the origin branch) so the branch can receive it normally.
     */
    private function resetWrongInTransit(
        Shipment $shipment,
        PickupRequest $pickup,
        ?int $actorId
    ): void {
        if ($this->shipmentHasColumn('transfer_status')) {
            $shipment->transfer_status = null;
        }
        if ($this->shipmentHasColumn('dispatched_at')) {
            $shipment->dispatched_at = null;
        }

        $this->changeShipmentStatus(
            shipment: $shipment,
            status: CourierStatus::PICKED_UP,
            userId: $actorId,
            note: sprintf(
                'Status corrected: was marked in transit without any TR. Still on pickup %s, waiting for origin branch receive.',
                $pickup->request_number ?? ('#'.$pickup->id)
            )
        );
    }

    private function dropFromOpenTr(
        int $itemId,
        int $manifestId,
        PickupRequest $pickup
    ): void {
        DB::table('dispatch_manifest_items')
            ->where('id', $itemId)
            ->where('status', 'added')
            ->update(array_intersect_key([
                'status' => 'cancelled',
                'discrepancy_note' => sprintf(
                    'Removed: parcel not yet received at branch (pickup %s).',
                    $pickup->request_number ?? ('#'.$pickup->id)
                ),
                'updated_at' => now(),
            ], array_flip(DB::getSchemaBuilder()->getColumnListing('dispatch_manifest_items'))));

        try {
            app(\Modules\Dispatch\Services\ManifestHopService::class)->refreshCounts($manifestId);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('pickup.reconcile.refresh_counts_failed', ['manifest_id' => $manifestId, 'error' => $e->getMessage()]);
        }
    }

    private function auditReconcile(
        PickupRequest $pickup,
        ?int $actorId,
        array $report,
        string $source
    ): void {
        \Illuminate\Support\Facades\Log::info('pickup.reconcile', ['pickup' => $pickup->request_number, 'actor_id' => $actorId, 'source' => $source, 'changes' => $report['changes']]);

        try {
            $schema = DB::getSchemaBuilder();
            if (! $schema->hasTable('audit_logs')) {
                return;
            }
            DB::table('audit_logs')->insert(array_intersect_key([
                'user_id' => $actorId,
                'action' => 'pickups.reconcile',
                'auditable_type' => PickupRequest::class,
                'auditable_id' => $pickup->id,
                'old_values' => json_encode(['status' => $report['status_before']]),
                'new_values' => json_encode([
                    'status' => $report['status_after'],
                    'source' => $source,
                    'items' => collect($report['items'])->whereNotNull('action')->map(fn ($r) => [
                        'tracking_number' => $r['tracking_number'],
                        'action' => $r['action'],
                        'was' => $r['shipment_status'],
                    ])->values(),
                    'received' => $report['received'],
                    'errors' => $report['errors'],
                ]),
                'ip_address' => app()->runningInConsole() ? 'console' : request()?->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ], array_flip($schema->getColumnListing('audit_logs'))));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('pickup.reconcile.audit_failed', ['pickup' => $pickup->id, 'error' => $e->getMessage()]);
        }
    }

    private function markPivotRejected(
        PickupRequestShipment $item,
        int $staffId,
        string $reason,
        string $type
    ): void {
        $item->status = PickupShipmentStatus::FAILED;

        if ($this->pivotHasColumn('collection_status')) {
            $item->collection_status = PickupShipmentStatus::FAILED;
        }

        $item->remarks = trim(
            sprintf('[%s] %s', $type, $reason)
        );

        if ($this->pivotHasColumn('removed_by')) {
            $item->removed_by = $staffId;
        }

        if ($this->pivotHasColumn('removed_at')) {
            $item->removed_at = now();
        }

        $item->save();
    }

    private function pivotHasColumn(
        string $column
    ): bool {
        return in_array(
            $column,
            DB::getSchemaBuilder()
                ->getColumnListing('pickup_request_shipments'),
            true
        );
    }

    private function pickupCompletedCallbackAlreadyFired(
        int $pickupId
    ): bool {
        if (! \Illuminate\Support\Facades\Schema::hasTable('pickup_callback_logs')) {
            return false;
        }

        return \Modules\Pickup\Models\PickupCallbackLog::query()
            ->where('pickup_request_id', $pickupId)
            ->where('event', 'pickup.completed')
            ->where('status', 'delivered')
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Cancel Pickup
    |
    | Rider can cancel if shipment is missing, service cannot be fulfilled,
    | or cutoff time passed. Status transitions to FAILED.
    |--------------------------------------------------------------------------
    */

    public function cancelByRider(
        PickupRequest $pickup,
        User $user,
        string $reason
    ): PickupRequest {
        return DB::transaction(
            function () use (
                $pickup,
                $user,
                $reason
            ): PickupRequest {
                $pickup = PickupRequest::query()
                    ->lockForUpdate()
                    ->findOrFail($pickup->id);

                $this->ensureAssignedRider(
                    pickup: $pickup,
                    user: $user
                );

                if (
                    ! in_array(
                        $pickup->status,
                        [
                            PickupStatus::ARRIVED,
                            PickupStatus::COLLECTED,
                        ],
                        true
                    )
                ) {
                    throw ValidationException::withMessages([
                        'status' => [
                            'Cannot cancel pickup at current stage.',
                        ],
                    ]);
                }

                $pickup->status = PickupStatus::FAILED;
                $pickup->failed_at = now();
                $pickup->failed_reason = $reason;
                $pickup->save();

                $this->createPickupEvent(
                    pickup: $pickup,
                    type: 'cancelled',
                    description: 'Pickup cancelled by rider: ' . $reason
                );

                return $this->get($pickup);
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Start Transit to Branch
    |
    | Rider marks all shipments collected and ready to leave for branch.
    | Status transitions: COLLECTED → ON_WAY_TO_BRANCH
    |--------------------------------------------------------------------------
    */

    public function startTransit(
        PickupRequest $pickup,
        User $user
    ): PickupRequest {
        $fresh = DB::transaction(
            function () use (
                $pickup,
                $user
            ): PickupRequest {
                $pickup = PickupRequest::query()
                    ->lockForUpdate()
                    ->findOrFail($pickup->id);

                $this->ensureAssignedRider(
                    pickup: $pickup,
                    user: $user
                );

                if (
                    $pickup->status !==
                    PickupStatus::COLLECTED
                ) {
                    throw ValidationException::withMessages([
                        'status' => [
                            'All shipments must be collected before starting transit.',
                        ],
                    ]);
                }

                $pickup->status = PickupStatus::ON_WAY_TO_BRANCH;
                $pickup->save();

                $this->createPickupEvent(
                    pickup: $pickup,
                    type: 'started_transit',
                    description: 'Rider started transit to origin branch.'
                );

                return $this->get($pickup);
            }
        );

        $this->callbacks->pickupOnWayToBranch($fresh);

        return $fresh;
    }

    /*
    |--------------------------------------------------------------------------
    | Fail
    |--------------------------------------------------------------------------
    */

    public function fail(
        PickupRequest $pickup,
        User $user,
        string $reason
    ): PickupRequest {
        return DB::transaction(
            function () use (
                $pickup,
                $user,
                $reason
            ): PickupRequest {
                $pickup = PickupRequest::query()
                    ->lockForUpdate()
                    ->findOrFail($pickup->id);

                if (
                    ! in_array(
                        $pickup->status,
                        PickupStatus::active(),
                        true
                    )
                ) {
                    throw ValidationException::withMessages([
                        'status' => [
                            'This pickup is already closed.',
                        ],
                    ]);
                }

                $this->ensureCanManagePickup(
                    pickup: $pickup,
                    user: $user
                );

                $pickup->status = PickupStatus::FAILED;
                $pickup->failed_at = now();
                $pickup->failed_reason = $reason;
                $pickup->save();

                $shipments = $pickup
                    ->activeShipments()
                    ->lockForUpdate()
                    ->get();

                foreach ($shipments as $shipment) {
                    if (! $shipment) {
                        continue;
                    }

                    if (
                        in_array(
                            $shipment->status,
                            [
                                CourierStatus::AWAITING_PICKUP,
                                CourierStatus::PICKUP_ASSIGNED,
                            ],
                            true
                        )
                    ) {
                        $this->changeShipmentStatus(
                            shipment: $shipment,
                            status: CourierStatus::AWAITING_PICKUP,
                            userId: $user->id,
                            note: 'Pickup failed. Shipment returned to awaiting pickup.'
                        );
                    }
                }

                $this->createPickupEvent(
                    pickup: $pickup,
                    type: 'failed',
                    description: $reason
                );

                return $this->get($pickup);
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Attach internally
    |--------------------------------------------------------------------------
    */

    private function attachToPickup(
        PickupRequest $pickup,
        Shipment $shipment,
        ?string $remarks
    ): PickupRequestShipment {
        $existing = PickupRequestShipment::query()
            ->where(
                'pickup_request_id',
                $pickup->id
            )
            ->where(
                'shipment_id',
                $shipment->id
            )
            ->whereNull('removed_at')
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return $existing;
        }

        if (
            $pickup->status !==
            PickupStatus::REQUESTED
        ) {
            if (
                $shipment->status ===
                CourierStatus::AWAITING_PICKUP
            ) {
                $this->changeShipmentStatus(
                    shipment: $shipment,
                    status: CourierStatus::PICKUP_ASSIGNED,
                    userId: null,
                    note: 'Shipment added to an already assigned pickup.'
                );
            }
        }

        return PickupRequestShipment::query()
            ->create([
                'pickup_request_id' => $pickup->id,
                'shipment_id' => $shipment->id,
                'remarks' => $remarks,
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Shipment status
    |--------------------------------------------------------------------------
    */

    private function changeShipmentStatus(
        Shipment $shipment,
        string $status,
        ?int $userId,
        string $note
    ): void {
        $oldStatus = $shipment->status;

        $shipment->status = $status;

        $shipment->merchant_status =
            CourierStatus::merchantStatus($status);

        if (
            $status === CourierStatus::PICKED_UP
            && $this->shipmentHasColumn('pickup_status')
        ) {
            $shipment->pickup_status = 'picked_up';
        }

        if (
            $status === CourierStatus::CANCELLED
            && $this->shipmentHasColumn('cancelled_at')
        ) {
            $shipment->cancelled_at = now();
        }

        $shipment->save();

        $this->createShipmentTrackingEvent(
            shipment: $shipment,
            oldStatus: $oldStatus,
            newStatus: $status,
            description: $note,
            createdBy: $userId
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Pickup event
    |--------------------------------------------------------------------------
    */

    private function createPickupEvent(
        PickupRequest $pickup,
        string $type,
        string $description
    ): void {
        // Keep any pickup run (PR) stop in step with the rider's workflow.
        try {
            app(PickupRunService::class)->syncFromPickupRequest($pickup);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::info('pickup_run.sync_skipped', ['pickup_request_id' => $pickup->id, 'error' => $e->getMessage()]);
        }

        $schema = DB::getSchemaBuilder();

        if (! $schema->hasTable('pickup_events')) {
            return;
        }

        $columns = $schema->getColumnListing(
            'pickup_events'
        );

        $data = [
            'pickup_request_id' => $pickup->id,
            'type' => $type,
            'description' => $description,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $data = array_intersect_key(
            $data,
            array_flip($columns)
        );

        DB::table('pickup_events')
            ->insert($data);
    }

    /*
    |--------------------------------------------------------------------------
    | Shipment tracking event
    |--------------------------------------------------------------------------
    */

    private function createShipmentTrackingEvent(
        Shipment $shipment,
        ?string $oldStatus,
        string $newStatus,
        string $description,
        ?int $createdBy = null
    ): void {
        $schema = DB::getSchemaBuilder();

        if (! $schema->hasTable('tracking_events')) {
            return;
        }

        $columns = $schema->getColumnListing(
            'tracking_events'
        );

        $data = [
            'shipment_id' => $shipment->id,

            'tracking_number' =>
                $shipment->tracking_number,

            'old_status' =>
                $oldStatus,

            'status' =>
                $newStatus,

            'merchant_status' =>
                CourierStatus::merchantStatus(
                    $newStatus
                ),

            'branch_id' =>
                $shipment->current_branch_id
                ??
                $shipment->origin_branch_id,

            'sub_branch_id' =>
                $shipment->current_sub_branch_id
                ??
                $shipment->origin_sub_branch_id,

            'location_text' =>
                null,

            'description' =>
                $description,

            'visibility' =>
                'public',

            'created_by' =>
                $createdBy,

            'created_at' =>
                now(),

            'updated_at' =>
                now(),
        ];

        $data = array_intersect_key(
            $data,
            array_flip($columns)
        );

        DB::table('tracking_events')
            ->insert($data);
    }

    /*
    |--------------------------------------------------------------------------
    | Rider validation
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    | users table only uses branch_id.
    | There is NO users.sub_branch_id.
    |--------------------------------------------------------------------------
    */

    private function validateStaffForPickup(
        PickupRequest $pickup,
        User $staff
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Active check
        |--------------------------------------------------------------------------
        */

        if (
            isset($staff->status)
            && $staff->status !== 'active'
        ) {
            throw ValidationException::withMessages([
                'staff_id' => [
                    'Selected rider is not active.',
                ],
            ]);
        }

        if (
            isset($staff->is_active)
            && ! (bool) $staff->is_active
        ) {
            throw ValidationException::withMessages([
                'staff_id' => [
                    'Selected rider is not active.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Pickup branch
        |--------------------------------------------------------------------------
        */

        $branchId = (int) (
            $pickup->pickup_branch_id
            ??
            $pickup->branch_id
            ??
            0
        );

        /*
        |--------------------------------------------------------------------------
        | Staff branch
        |--------------------------------------------------------------------------
        |
        | ONLY branch_id.
        |
        */

        if (
            $branchId > 0
            && $staff->branch_id !== null
            && (int) $staff->branch_id !== $branchId
        ) {
            throw ValidationException::withMessages([
                'staff_id' => [
                    'Selected rider does not belong to the pickup branch.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Role
        |--------------------------------------------------------------------------
        */

        if (
            method_exists($staff, 'hasAnyRole')
            && ! $staff->hasAnyRole([
                'rider',
                'pickup_rider',
                'staff',
                'delivery_staff',
            ])
        ) {
            throw ValidationException::withMessages([
                'staff_id' => [
                    'Selected user is not an eligible pickup rider.',
                ],
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Assigned rider
    |--------------------------------------------------------------------------
    */

    private function ensureAssignedRider(
        PickupRequest $pickup,
        User $user
    ): void {
        if (
            $pickup->assigned_to === null
            ||
            (int) $pickup->assigned_to !== (int) $user->id
        ) {
            throw ValidationException::withMessages([
                'pickup' => [
                    'Only the assigned rider can perform this action.',
                ],
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Management permission
    |--------------------------------------------------------------------------
    */

    private function ensureCanManagePickup(
        PickupRequest $pickup,
        User $user
    ): void {
        if (
            $user->isSuperAdmin()
            ||
            $user->hasRole('main_admin')
            ||
            $user->hasRole('admin')
        ) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Branch manager
        |--------------------------------------------------------------------------
        |
        | Branch manager uses users.branch_id.
        | No sub_branch_id is read from users.
        |--------------------------------------------------------------------------
        */

        if (
            $user->hasRole('branch_manager')
            ||
            $user->hasRole('sub_branch_manager')
        ) {
            $userBranchId = $this->resolveUserBranchId(
                $user
            );

            $pickupBranchId = (int) (
                $pickup->pickup_branch_id
                ??
                $pickup->branch_id
                ??
                0
            );

            if (
                $userBranchId > 0
                &&
                $pickupBranchId > 0
                &&
                $userBranchId === $pickupBranchId
            ) {
                return;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Assigned rider
        |--------------------------------------------------------------------------
        */

        if (
            $pickup->assigned_to !== null
            &&
            (int) $pickup->assigned_to ===
            (int) $user->id
        ) {
            return;
        }

        throw ValidationException::withMessages([
            'pickup' => [
                'You are not allowed to manage this pickup.',
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | User branch
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    | Never reference users.sub_branch_id.
    |--------------------------------------------------------------------------
    */

    private function resolveUserBranchId(
        User $user
    ): int {
        return (int) (
            $user->branch_id
            ??
            0
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Pickup schema
    |--------------------------------------------------------------------------
    */

    private function pickupHasColumn(
        string $column
    ): bool {
        return in_array(
            $column,
            DB::getSchemaBuilder()
                ->getColumnListing('pickup_requests'),
            true
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Shipment schema
    |--------------------------------------------------------------------------
    */

    private function shipmentHasColumn(
        string $column
    ): bool {
        return in_array(
            $column,
            DB::getSchemaBuilder()
                ->getColumnListing('shipments'),
            true
        );
    }
}