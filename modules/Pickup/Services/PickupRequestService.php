<?php

declare(strict_types=1);

namespace Modules\Pickup\Services;

use App\Models\User;
use App\Support\CourierStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Pickup\Models\PickupRequest;
use Modules\Pickup\Models\PickupRequestShipment;
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
        User $staff
    ): PickupRequestShipment {
        $result = DB::transaction(
            function () use (
                $pickup,
                $shipment,
                $staff
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

                if (
                    $shipment->status !==
                    CourierStatus::PICKED_UP
                ) {
                    throw ValidationException::withMessages([
                        'shipment' => [
                            'Shipment must be picked up before origin branch receiving.',
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

        $this->callbacks->shipmentReceivedAtOrigin(
            pickup: $result->pickupRequest,
            shipment: $result->shipment
        );

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
                if (
                    $shipment->status !==
                    CourierStatus::PICKED_UP
                ) {
                    throw ValidationException::withMessages([
                        'shipment' => [
                            'Only a picked-up shipment can be rejected at the branch.',
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
        if (
            ! $pickup
            || $pickup->status !== PickupStatus::ON_WAY_TO_BRANCH
        ) {
            return null;
        }

        $pickupRequest = PickupRequest::query()
            ->with('shipments')
            ->find($pickup->id);

        if (! $pickupRequest) {
            return null;
        }

        $unresolved = $pickupRequest->shipments
            ->filter(function (Shipment $s): bool {
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
        $completedCallbackSent = \Modules\Pickup\Models\PickupCallbackLog::query()
            ->where('pickup_request_id', $pickupRequest->id)
            ->where('event', 'pickup.completed')
            ->whereIn('status', ['delivered', 'pending', 'queued'])
            ->exists();

        $pickupRequest->status = PickupStatus::COMPLETED;

        if ($this->pickupHasColumn('completed_at')) {
            $pickupRequest->completed_at = now();
        }

        $pickupRequest->save();

        if (! $completedCallbackSent) {
            $this->callbacks->pickupCompleted($pickupRequest);
        }

        return $pickupRequest;
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