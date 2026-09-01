<?php

declare(strict_types=1);

namespace Modules\Pickup\Services;

use App\Models\User;
use App\Support\CourierStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Pickup\Models\PickupRequest;
use Modules\Pickup\Models\PickupRequestShipment;
use Modules\Pickup\Support\PickupStatus;
use Modules\Shipment\Models\Shipment;

final class PickupRequestService
{
    /*
    |--------------------------------------------------------------------------
    | GET
    |--------------------------------------------------------------------------
    */

    public function get(
        PickupRequest $pickup
    ): PickupRequest {
        return $pickup->fresh([
            'merchant',
            'branch',
            'subBranch',
            'pickupBranch',
            'pickupSubBranch',
            'pickupLocation',
            'assignedStaff',
            'assignedBy',
            'pickedUpBy',
            'shipments.shipment',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | ATTACH SHIPMENT MANUALLY
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
                $shipment = Shipment::query()
                    ->lockForUpdate()
                    ->find($shipment->id);

                if (! $shipment) {
                    throw ValidationException::withMessages([
                        'shipment' => [
                            'Shipment was not found.',
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
    | ASSIGN RIDER
    |--------------------------------------------------------------------------
    */

    public function assign(
        PickupRequest $pickup,
        User $staff,
        User $assignedBy
    ): PickupRequest {
        return DB::transaction(
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
                    &&
                    $pickup->accepted_at === null
                ) {
                    $pickup->accepted_at = now();
                }

                $pickup->save();

                /*
                 * Any shipment already attached to the pickup
                 * must now be pickup_assigned.
                 */
                $items = $pickup
                    ->activeShipments()
                    ->with('shipment')
                    ->lockForUpdate()
                    ->get();

                foreach ($items as $item) {
                    $shipment = $item->shipment;

                    if (! $shipment) {
                        continue;
                    }

                    $shipment = Shipment::query()
                        ->lockForUpdate()
                        ->find($shipment->id);

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
                            note: $oldStaffId !== null
                                ? 'Pickup rider reassigned.'
                                : 'Pickup rider assigned.'
                        );
                    }
                }

                $this->createPickupEvent(
                    pickup: $pickup,
                    type: $oldStaffId !== null
                        ? 'rider_reassigned'
                        : 'rider_assigned',
                    description: $oldStaffId !== null
                        ? 'Pickup rider reassigned.'
                        : 'Pickup rider assigned.'
                );

                return $this->get($pickup);
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | TRANSFER
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
                    &&
                    (int) $oldStaffId === (int) $newStaff->id
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
                $pickup->save();

                $this->createPickupEvent(
                    pickup: $pickup,
                    type: 'transferred',
                    description: $reason
                );

                $items = $pickup
                    ->activeShipments()
                    ->with('shipment')
                    ->lockForUpdate()
                    ->get();

                foreach ($items as $item) {
                    $shipment = $item->shipment;

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
    | START
    |--------------------------------------------------------------------------
    */

    public function start(
        PickupRequest $pickup,
        User $user
    ): PickupRequest {
        return DB::transaction(
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
                            'Pickup must be assigned before it can be started.',
                        ],
                    ]);
                }

                $pickup->status = PickupStatus::STARTED;

                if (
                    $this->pickupHasColumn('accepted_at')
                    &&
                    $pickup->accepted_at === null
                ) {
                    $pickup->accepted_at = now();
                }

                $pickup->save();

                /*
                 * Make sure attached shipments are pickup_assigned.
                 */
                $items = $pickup
                    ->activeShipments()
                    ->with('shipment')
                    ->lockForUpdate()
                    ->get();

                foreach ($items as $item) {
                    $shipment = $item->shipment;

                    if (! $shipment) {
                        continue;
                    }

                    if (
                        $shipment->status ===
                        CourierStatus::AWAITING_PICKUP
                    ) {
                        $this->changeShipmentStatus(
                            shipment: $shipment,
                            status: CourierStatus::PICKUP_ASSIGNED,
                            userId: $user->id,
                            note: 'Pickup started by assigned rider.'
                        );
                    }
                }

                $this->createPickupEvent(
                    pickup: $pickup,
                    type: 'started',
                    description: 'Rider started travelling to pickup location.'
                );

                return $this->get($pickup);
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ARRIVE
    |--------------------------------------------------------------------------
    */

    public function arrive(
        PickupRequest $pickup,
        User $user
    ): PickupRequest {
        return DB::transaction(
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

                if (
                    $this->pickupHasColumn('arrived_at')
                ) {
                    $pickup->arrived_at = now();
                }

                $pickup->save();

                $this->createPickupEvent(
                    pickup: $pickup,
                    type: 'arrived',
                    description: 'Rider arrived at pickup location.'
                );

                return $this->get($pickup);
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | COLLECT SHIPMENT
    |--------------------------------------------------------------------------
    */

    public function collectShipment(
        PickupRequest $pickup,
        Shipment $shipment,
        User $user,
        ?string $remarks = null
    ): PickupRequestShipment {
        return DB::transaction(
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

                $shipment = Shipment::query()
                    ->lockForUpdate()
                    ->find($shipment->id);

                if (! $shipment) {
                    throw ValidationException::withMessages([
                        'shipment' => [
                            'Shipment was not found.',
                        ],
                    ]);
                }

                $this->ensureShipmentBelongsToPickup(
                    pickup: $pickup,
                    shipment: $shipment
                );

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
                    note: 'Shipment collected by pickup rider.'
                );

                if ($remarks !== null) {
                    $item->remarks = $remarks;
                    $item->save();
                }

                if (
                    $this->pickupHasColumn('picked_up_at')
                ) {
                    $pickup->picked_up_at =
                        $pickup->picked_up_at ?? now();
                }

                if (
                    $this->pickupHasColumn('picked_up_by')
                ) {
                    $pickup->picked_up_by = $user->id;
                }

                $pickup->save();

                $this->createPickupEvent(
                    pickup: $pickup,
                    type: 'shipment_collected',
                    description:
                        'Shipment ' .
                        ($shipment->tracking_number ?? '#' . $shipment->id) .
                        ' collected by rider.'
                );

                return $item->fresh([
                    'pickupRequest',
                    'shipment',
                ]);
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RECEIVE SHIPMENT AT ORIGIN
    |--------------------------------------------------------------------------
    */

    public function receiveShipment(
        PickupRequest $pickup,
        Shipment $shipment,
        User $staff
    ): PickupRequestShipment {
        return DB::transaction(
            function () use (
                $pickup,
                $shipment,
                $staff
            ): PickupRequestShipment {
                $pickup = PickupRequest::query()
                    ->lockForUpdate()
                    ->findOrFail($pickup->id);

                $shipment = Shipment::query()
                    ->lockForUpdate()
                    ->find($shipment->id);

                if (! $shipment) {
                    throw ValidationException::withMessages([
                        'shipment' => [
                            'Shipment was not found.',
                        ],
                    ]);
                }

                $this->ensureShipmentBelongsToPickup(
                    pickup: $pickup,
                    shipment: $shipment
                );

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
                 * Shipment has physically moved from merchant
                 * pickup location to origin branch.
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
                 * RECEIVED_AT_ORIGIN is optional in your current
                 * CourierStatus implementation.
                 */
                $receivedStatus =
                    $this->courierStatusValue(
                        'RECEIVED_AT_ORIGIN'
                    );

                if ($receivedStatus !== null) {
                    $this->changeShipmentStatus(
                        shipment: $shipment,
                        status: $receivedStatus,
                        userId: $staff->id,
                        note: 'Shipment received at origin branch.'
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

                $this->createPickupEvent(
                    pickup: $pickup,
                    type: 'shipment_received',
                    description:
                        'Shipment ' .
                        ($shipment->tracking_number ?? '#' . $shipment->id) .
                        ' received at origin branch.'
                );

                return $item->fresh([
                    'pickupRequest',
                    'shipment',
                ]);
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | COMPLETE
    |--------------------------------------------------------------------------
    */

    public function complete(
        PickupRequest $pickup,
        User $user
    ): PickupRequest {
        return DB::transaction(
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
                    PickupStatus::ARRIVED
                ) {
                    throw ValidationException::withMessages([
                        'status' => [
                            'Rider must arrive before completing the pickup.',
                        ],
                    ]);
                }

                $items = $pickup
                    ->activeShipments()
                    ->with('shipment')
                    ->lockForUpdate()
                    ->get();

                if ($items->isEmpty()) {
                    throw ValidationException::withMessages([
                        'shipments' => [
                            'A pickup cannot be completed without shipments.',
                        ],
                    ]);
                }

                $pending = $items->filter(
                    static function (
                        PickupRequestShipment $item
                    ): bool {
                        $shipment = $item->shipment;

                        if (! $shipment) {
                            return true;
                        }

                        return ! in_array(
                            $shipment->status,
                            [
                                CourierStatus::PICKED_UP,
                                CourierStatus::CANCELLED,
                            ],
                            true
                        );
                    }
                );

                if ($pending->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'shipments' => [
                            'All shipments must be collected before completing the pickup.',
                        ],
                    ]);
                }

                $pickup->status = PickupStatus::COMPLETED;
                $pickup->completed_at = now();
                $pickup->save();

                $this->createPickupEvent(
                    pickup: $pickup,
                    type: 'completed',
                    description: 'Pickup completed successfully.'
                );

                return $this->get($pickup);
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | FAIL
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

                if (
                    $this->pickupHasColumn('failed_at')
                ) {
                    $pickup->failed_at = now();
                }

                if (
                    $this->pickupHasColumn('failed_reason')
                ) {
                    $pickup->failed_reason = $reason;
                }

                $pickup->save();

                $items = $pickup
                    ->activeShipments()
                    ->with('shipment')
                    ->lockForUpdate()
                    ->get();

                foreach ($items as $item) {
                    $shipment = $item->shipment;

                    if (! $shipment) {
                        continue;
                    }

                    $shipment = Shipment::query()
                        ->lockForUpdate()
                        ->find($shipment->id);

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
    | ATTACH INTERNALLY
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

        $this->ensureShipmentBelongsToPickup(
            pickup: $pickup,
            shipment: $shipment
        );

        /*
         * Shipment must not already belong to another active pickup.
         */
        $alreadyActive = $shipment
            ->pickupRequests()
            ->whereIn(
                'pickup_requests.status',
                PickupStatus::active()
            )
            ->where(
                'pickup_requests.id',
                '!=',
                $pickup->id
            )
            ->exists();

        if ($alreadyActive) {
            throw ValidationException::withMessages([
                'shipment' => [
                    'Shipment already belongs to another active pickup.',
                ],
            ]);
        }

        $item = PickupRequestShipment::query()
            ->create([
                'pickup_request_id' => $pickup->id,
                'shipment_id' => $shipment->id,
                'remarks' => $remarks,
            ]);

        /*
         * If rider has already been assigned,
         * shipment must immediately become pickup_assigned.
         */
        if (
            in_array(
                $pickup->status,
                [
                    PickupStatus::ASSIGNED,
                    PickupStatus::STARTED,
                    PickupStatus::ARRIVED,
                ],
                true
            )
            &&
            $shipment->status ===
            CourierStatus::AWAITING_PICKUP
        ) {
            $this->changeShipmentStatus(
                shipment: $shipment,
                status: CourierStatus::PICKUP_ASSIGNED,
                userId: $pickup->assigned_to !== null
                    ? (int) $pickup->assigned_to
                    : null,
                note: 'Shipment added to the active pickup.'
            );
        }

        return $item;
    }

    /*
    |--------------------------------------------------------------------------
    | SHIPMENT STATUS
    |--------------------------------------------------------------------------
    */

    private function changeShipmentStatus(
        Shipment $shipment,
        string $status,
        ?int $userId,
        string $note
    ): void {
        $oldStatus = $shipment->status;

        if (
            $oldStatus === $status
        ) {
            $this->createShipmentTrackingEvent(
                shipment: $shipment,
                oldStatus: $oldStatus,
                newStatus: $status,
                description: $note,
                createdBy: $userId
            );

            return;
        }

        $shipment->status = $status;

        if (
            $this->shipmentHasColumn('merchant_status')
        ) {
            $shipment->merchant_status =
                CourierStatus::merchantStatus($status);
        }

        if (
            $status === CourierStatus::PICKED_UP
            &&
            $this->shipmentHasColumn('pickup_status')
        ) {
            $shipment->pickup_status = 'picked_up';
        }

        if (
            $status === CourierStatus::CANCELLED
            &&
            $this->shipmentHasColumn('cancelled_at')
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
    | PICKUP EVENT
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

        if ($data !== []) {
            DB::table('pickup_events')
                ->insert($data);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SHIPMENT TRACKING EVENT
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
            'tracking_number' => $shipment->tracking_number,
            'old_status' => $oldStatus,
            'status' => $newStatus,
            'merchant_status' =>
                CourierStatus::merchantStatus($newStatus),

            'branch_id' =>
                $shipment->current_branch_id
                ??
                $shipment->origin_branch_id,

            'sub_branch_id' =>
                $shipment->current_sub_branch_id
                ??
                $shipment->origin_sub_branch_id,

            'location_text' => null,
            'description' => $description,
            'visibility' => 'public',
            'created_by' => $createdBy,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $data = array_intersect_key(
            $data,
            array_flip($columns)
        );

        if ($data !== []) {
            DB::table('tracking_events')
                ->insert($data);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATE STAFF
    |--------------------------------------------------------------------------
    */

    private function validateStaffForPickup(
        PickupRequest $pickup,
        User $staff
    ): void {
        /*
         * Use the actual active flag when available.
         */
        if (
            isset($staff->is_active)
            &&
            ! (bool) $staff->is_active
        ) {
            throw ValidationException::withMessages([
                'staff_id' => [
                    'Selected rider is not active.',
                ],
            ]);
        }

        if (
            isset($staff->status)
            &&
            $staff->status !== 'active'
        ) {
            throw ValidationException::withMessages([
                'staff_id' => [
                    'Selected rider is not active.',
                ],
            ]);
        }

        $pickupBranchId =
            $this->pickupBranchId($pickup);

        $pickupSubBranchId =
            $this->pickupSubBranchId($pickup);

        /*
         * Branch must match.
         */
        if (
            $pickupBranchId !== null
            &&
            isset($staff->branch_id)
            &&
            (int) $staff->branch_id !==
            (int) $pickupBranchId
        ) {
            throw ValidationException::withMessages([
                'staff_id' => [
                    'Selected rider does not belong to the pickup branch.',
                ],
            ]);
        }

        /*
         * If both sides have sub-branch information,
         * enforce it.
         */
        if (
            $pickupSubBranchId !== null
            &&
            isset($staff->sub_branch_id)
            &&
            $staff->sub_branch_id !== null
            &&
            (int) $staff->sub_branch_id !==
            (int) $pickupSubBranchId
        ) {
            throw ValidationException::withMessages([
                'staff_id' => [
                    'Selected rider does not belong to the pickup sub-branch.',
                ],
            ]);
        }

        if (
            method_exists($staff, 'hasAnyRole')
            &&
            ! $staff->hasAnyRole([
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
    | ENSURE ASSIGNED RIDER
    |--------------------------------------------------------------------------
    */

    private function ensureAssignedRider(
        PickupRequest $pickup,
        User $user
    ): void {
        if (
            $pickup->assigned_to === null
            ||
            (int) $pickup->assigned_to !==
            (int) $user->id
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
    | MANAGEMENT PERMISSION
    |--------------------------------------------------------------------------
    */

    private function ensureCanManagePickup(
        PickupRequest $pickup,
        User $user
    ): void {
        if (
            method_exists($user, 'isSuperAdmin')
            &&
            $user->isSuperAdmin()
        ) {
            return;
        }

        if (
            method_exists($user, 'hasAnyRole')
            &&
            $user->hasAnyRole([
                'super_admin',
                'admin',
                'main_admin',
            ])
        ) {
            return;
        }

        /*
         * Branch manager.
         */
        if (
            method_exists($user, 'hasRole')
            &&
            $user->hasRole('branch_manager')
        ) {
            $userBranchId =
                $this->resolveUserBranchId($user);

            if (
                $userBranchId !== null
                &&
                $userBranchId ===
                $this->pickupBranchId($pickup)
            ) {
                return;
            }
        }

        /*
         * Sub branch manager.
         */
        if (
            method_exists($user, 'hasRole')
            &&
            $user->hasRole('sub_branch_manager')
        ) {
            $userSubBranchId =
                $this->resolveUserSubBranchId($user);

            if (
                $userSubBranchId !== null
                &&
                $userSubBranchId ===
                $this->pickupSubBranchId($pickup)
            ) {
                return;
            }
        }

        /*
         * Assigned rider can manage their own pickup.
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
    | SHIPMENT OWNERSHIP
    |--------------------------------------------------------------------------
    */

    private function ensureShipmentBelongsToPickup(
        PickupRequest $pickup,
        Shipment $shipment
    ): void {
        if (
            (int) $pickup->merchant_id !==
            (int) $shipment->merchant_id
        ) {
            throw ValidationException::withMessages([
                'shipment' => [
                    'Shipment does not belong to this pickup merchant.',
                ],
            ]);
        }

        if (
            (int) $pickup->pickup_location_id !==
            (int) $shipment->pickup_location_id
        ) {
            throw ValidationException::withMessages([
                'shipment' => [
                    'Shipment does not belong to this pickup location.',
                ],
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | BRANCH HELPERS
    |--------------------------------------------------------------------------
    */

    private function pickupBranchId(
        PickupRequest $pickup
    ): ?int {
        $value =
            $pickup->pickup_branch_id
            ??
            $pickup->branch_id
            ??
            null;

        return $value !== null
            ? (int) $value
            : null;
    }

    private function pickupSubBranchId(
        PickupRequest $pickup
    ): ?int {
        $value =
            $pickup->pickup_sub_branch_id
            ??
            $pickup->sub_branch_id
            ??
            null;

        return $value !== null
            ? (int) $value
            : null;
    }

    private function resolveUserBranchId(
        User $user
    ): ?int {
        if ($user->branch_id !== null) {
            return (int) $user->branch_id;
        }

        return null;
    }

    private function resolveUserSubBranchId(
        User $user
    ): ?int {
        if ($user->sub_branch_id !== null) {
            return (int) $user->sub_branch_id;
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | SCHEMA HELPERS
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

    /*
    |--------------------------------------------------------------------------
    | OPTIONAL COURIER STATUS
    |--------------------------------------------------------------------------
    */

    private function courierStatusValue(
        string $constant
    ): ?string {
        $constantName =
            CourierStatus::class .
            '::' .
            $constant;

        if (! defined($constantName)) {
            return null;
        }

        $value = constant($constantName);

        return is_string($value)
            ? $value
            : null;
    }
}