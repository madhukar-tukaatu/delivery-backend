<?php

declare(strict_types=1);

namespace Modules\Pickup\Services;

use App\Models\User;
use App\Support\CourierStatus;
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
            'shipments.shipment',
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

                /*
                 * If your database has accepted_at, mark acceptance here.
                 */
                if (
                    $this->pickupHasColumn('accepted_at')
                    && $pickup->accepted_at === null
                ) {
                    $pickup->accepted_at = now();
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
                 * A transfer does not restart the pickup.
                 *
                 * If already STARTED, it remains STARTED.
                 * If ASSIGNED, it remains ASSIGNED.
                 */
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
    | Start
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
                    && $pickup->accepted_at === null
                ) {
                    $pickup->accepted_at = now();
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
                $pickup->save();

                return $item->fresh([
                    'pickupRequest',
                    'shipment',
                ]);
            }
        );
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
        return DB::transaction(
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
                    ->firstOrFail();

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

                $shipment->current_branch_id =
                    $shipment->origin_branch_id;

                $shipment->current_sub_branch_id =
                    $shipment->origin_sub_branch_id;

                if (
                    defined(
                        CourierStatus::class .
                        '::RECEIVED_AT_ORIGIN'
                    )
                ) {
                    $this->changeShipmentStatus(
                        shipment: $shipment,
                        status: CourierStatus::RECEIVED_AT_ORIGIN,
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

                return $item->fresh([
                    'pickupRequest',
                    'shipment',
                ]);
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Complete
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

                $pending = $pickup
                    ->activeShipments()
                    ->with('shipment')
                    ->lockForUpdate()
                    ->get()
                    ->filter(
                        static function (
                            PickupRequestShipment $item
                        ): bool {
                            $shipment = $item->shipment;

                            if (! $shipment) {
                                return false;
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
    | Fail / Cancel
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
        if (
            ! DB::getSchemaBuilder()
                ->hasTable('pickup_events')
        ) {
            return;
        }

        $columns = DB::getSchemaBuilder()
            ->getColumnListing('pickup_events');

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
        if (
            ! DB::getSchemaBuilder()
                ->hasTable('tracking_events')
        ) {
            return;
        }

        $columns = DB::getSchemaBuilder()
            ->getColumnListing('tracking_events');

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

        DB::table('tracking_events')
            ->insert($data);
    }

    /*
    |--------------------------------------------------------------------------
    | Rider validation
    |--------------------------------------------------------------------------
    */

    private function validateStaffForPickup(
        PickupRequest $pickup,
        User $staff
    ): void {
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

        $branchId = (int) (
            $pickup->pickup_branch_id
            ??
            $pickup->branch_id
            ??
            0
        );

        if (
            $branchId > 0
            && isset($staff->branch_id)
            && (int) $staff->branch_id !== $branchId
        ) {
            throw ValidationException::withMessages([
                'staff_id' => [
                    'Selected rider does not belong to the pickup branch.',
                ],
            ]);
        }

        if (
            method_exists($staff, 'hasAnyRole')
            && ! $staff->hasAnyRole([
                'rider',
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
        ) {
            return;
        }

        if (
            $user->hasRole('branch_manager')
            ||
            $user->hasRole('sub_branch_manager')
        ) {
            $branchId = $this->resolveUserBranchId($user);

            if (
                $branchId > 0
                &&
                in_array(
                    $branchId,
                    [
                        (int) $pickup->pickup_branch_id,
                        (int) $pickup->pickup_sub_branch_id,
                        (int) $pickup->branch_id,
                        (int) $pickup->sub_branch_id,
                    ],
                    true
                )
            ) {
                return;
            }
        }

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
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function resolveUserBranchId(
        User $user
    ): int {
        return (int) (
            $user->branch_id
            ??
            $user->sub_branch_id
            ??
            0
        );
    }

    private function pickupHasColumn(
        string $column
    ): bool {
        return array_key_exists(
            $column,
            array_flip(
                DB::getSchemaBuilder()
                    ->getColumnListing('pickup_requests')
            )
        );
    }

    private function shipmentHasColumn(
        string $column
    ): bool {
        return array_key_exists(
            $column,
            array_flip(
                DB::getSchemaBuilder()
                    ->getColumnListing('shipments')
            )
        );
    }
}