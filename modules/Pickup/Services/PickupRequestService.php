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
    | Get pickup
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
    | Attach shipment
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

                $pickup =
                    PickupRequest::query()
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

                $pickup =
                    PickupRequest::query()
                        ->lockForUpdate()
                        ->findOrFail(
                            $pickup->id
                        );

                if (
                    ! in_array(
                        $pickup->status,
                        PickupStatus::active(),
                        true
                    )
                ) {
                    throw ValidationException::withMessages([
                        'status' => [
                            'This pickup is no longer open.',
                        ],
                    ]);
                }

                $pickup->assigned_to =
                    $staff->id;

                $pickup->assigned_by =
                    $assignedBy->id;

                $pickup->assigned_at =
                    now();

                /*
                |--------------------------------------------------------------------------
                | If request was waiting, move to assigned
                |--------------------------------------------------------------------------
                */

                $pickup->status =
                    PickupStatus::ASSIGNED;

                $pickup->save();

                return $pickup->fresh([
                    'merchant',
                    'pickupLocation',
                    'assignedStaff',
                    'assignedBy',
                    'shipments.shipment',
                ]);
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

                $pickup =
                    $pickup
                        ->newQuery()
                        ->lockForUpdate()
                        ->findOrFail(
                            $pickup->id
                        );

                $this->ensureAssignedRider(
                    $pickup,
                    $user
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

                $pickup->status =
                    PickupStatus::STARTED;

                $pickup->accepted_at =
                    $pickup->accepted_at
                    ?? now();

                $pickup->save();

                return $pickup->fresh([
                    'assignedStaff',
                    'shipments.shipment',
                ]);
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

                $pickup =
                    $pickup
                        ->newQuery()
                        ->lockForUpdate()
                        ->findOrFail(
                            $pickup->id
                        );

                $this->ensureAssignedRider(
                    $pickup,
                    $user
                );

                if (
                    ! in_array(
                        $pickup->status,
                        [
                            PickupStatus::STARTED,
                        ],
                        true
                    )
                ) {
                    throw ValidationException::withMessages([
                        'status' => [
                            'Pickup must be started before rider arrival.',
                        ],
                    ]);
                }

                $pickup->status =
                    PickupStatus::ARRIVED;

                $pickup->arrived_at =
                    now();

                $pickup->save();

                return $pickup->fresh([
                    'assignedStaff',
                    'shipments.shipment',
                ]);
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

                $pickup =
                    PickupRequest::query()
                        ->lockForUpdate()
                        ->findOrFail(
                            $pickup->id
                        );

                $this->ensureAssignedRider(
                    $pickup,
                    $user
                );

                if (
                    $pickup->status !==
                    PickupStatus::ARRIVED
                ) {
                    throw ValidationException::withMessages([
                        'status' => [
                            'Rider must arrive at the pickup location before collecting shipments.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Verify shipment belongs to this pickup
                |--------------------------------------------------------------------------
                */

                $item =
                    PickupRequestShipment::query()
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

                /*
                |--------------------------------------------------------------------------
                | Shipment state
                |--------------------------------------------------------------------------
                */

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
                    $shipment->status !==
                    CourierStatus::PICKUP_ASSIGNED
                    &&
                    $shipment->status !==
                    CourierStatus::AWAITING_PICKUP
                ) {
                    throw ValidationException::withMessages([
                        'shipment' => [
                            'Shipment is not ready for collection.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Mark shipment picked up
                |--------------------------------------------------------------------------
                */

                $shipment->status =
                    CourierStatus::PICKED_UP;

                $shipment->merchant_status =
                    CourierStatus::merchantStatus(
                        CourierStatus::PICKED_UP
                    );

                $shipment->pickup_status =
                    'picked_up';

                $shipment->save();

                /*
                |--------------------------------------------------------------------------
                | Pivot remarks
                |--------------------------------------------------------------------------
                */

                if ($remarks !== null) {
                    $item->remarks =
                        $remarks;

                    $item->save();
                }

                /*
                |--------------------------------------------------------------------------
                | Pickup timestamp
                |--------------------------------------------------------------------------
                */

                $pickup->picked_up_at =
                    $pickup->picked_up_at
                    ?? now();

                $pickup->picked_up_by =
                    $user->id;

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
    | Receive shipment at origin branch
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

                $item =
                    PickupRequestShipment::query()
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

                /*
                |--------------------------------------------------------------------------
                | Move shipment into origin branch
                |--------------------------------------------------------------------------
                */

                $shipment->current_branch_id =
                    $shipment->origin_branch_id;

                $shipment->current_sub_branch_id =
                    $shipment->origin_sub_branch_id;

                /*
                |--------------------------------------------------------------------------
                | Do not invent another shipment status here.
                |--------------------------------------------------------------------------
                |
                | Keep the existing shipment workflow's status unless your
                | CourierStatus class already defines an explicit
                | RECEIVED_AT_ORIGIN status.
                |
                */

                if (
                    defined(
                        CourierStatus::class . '::RECEIVED_AT_ORIGIN'
                    )
                ) {
                    $shipment->status =
                        CourierStatus::RECEIVED_AT_ORIGIN;
                }

                $shipment->save();

                /*
                |--------------------------------------------------------------------------
                | Pickup timestamp
                |--------------------------------------------------------------------------
                */

                $pickup->received_at_origin_at =
                    now();

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
    | Complete pickup
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

                $pickup =
                    PickupRequest::query()
                        ->lockForUpdate()
                        ->findOrFail(
                            $pickup->id
                        );

                $this->ensureAssignedRider(
                    $pickup,
                    $user
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

                /*
                |--------------------------------------------------------------------------
                | Find remaining shipments
                |--------------------------------------------------------------------------
                */

                $pending =
                    $pickup
                        ->activeShipments()
                        ->with('shipment')
                        ->get()
                        ->filter(
                            static function (
                                PickupRequestShipment $item
                            ): bool {

                                $shipment =
                                    $item->shipment;

                                return $shipment
                                    && ! in_array(
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
                            'All shipments in this pickup must be collected before closing the pickup.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Close pickup
                |--------------------------------------------------------------------------
                */

                $pickup->status =
                    PickupStatus::COMPLETED;

                $pickup->completed_at =
                    now();

                $pickup->save();

                return $pickup->fresh([
                    'merchant',
                    'pickupLocation',
                    'assignedStaff',
                    'shipments.shipment',
                ]);
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Fail pickup
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

                $pickup =
                    PickupRequest::query()
                        ->lockForUpdate()
                        ->findOrFail(
                            $pickup->id
                        );

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

                if (
                    $pickup->assigned_to !== null
                    &&
                    (int) $pickup->assigned_to !==
                    (int) $user->id
                    &&
                    ! $user->isSuperAdmin()
                    &&
                    ! $user->hasRole('main_admin')
                    &&
                    ! $user->hasRole('branch_manager')
                    &&
                    ! $user->hasRole('sub_branch_manager')
                ) {
                    throw ValidationException::withMessages([
                        'pickup' => [
                            'You are not allowed to fail this pickup.',
                        ],
                    ]);
                }

                $pickup->status =
                    PickupStatus::FAILED;

                $pickup->failed_at =
                    now();

                $pickup->failed_reason =
                    $reason;

                $pickup->save();

                return $pickup->fresh([
                    'merchant',
                    'pickupLocation',
                    'assignedStaff',
                    'shipments.shipment',
                ]);
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Internal helper
    |--------------------------------------------------------------------------
    */

    private function attachToPickup(
        PickupRequest $pickup,
        Shipment $shipment,
        ?string $remarks
    ): PickupRequestShipment {

        $existing =
            PickupRequestShipment::query()
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

        return PickupRequestShipment::query()->create([
            'pickup_request_id' =>
                $pickup->id,

            'shipment_id' =>
                $shipment->id,

            'remarks' =>
                $remarks,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Rider validation
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
}