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
use Modules\Shipment\Enums\ShipmentStatus;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Services\ShipmentService;
use Modules\Tracking\Services\TrackingService;

final class PickupRequestService
{
    public function __construct(
        private readonly PickupRequestNumberService $numbers,
        private readonly TrackingService $trackingService,
        private readonly ShipmentService $shipmentService,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Attach Shipment
    |--------------------------------------------------------------------------
    */

    public function attachShipment(
        Shipment $shipment,
        ?int $userId = null,
        ?string $remarks = null
    ): PickupRequestShipment {

        return DB::transaction(function () use (
            $shipment,
            $userId,
            $remarks
        ) {

            /*
             * Lock shipment so two requests cannot
             * attach it simultaneously.
             */
            $shipment = Shipment::query()
                ->whereKey($shipment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->validateShipmentForPickup(
                $shipment
            );

            /*
             * Origin branch MUST already be resolved
             * when shipment was created.
             *
             * This comes from merchant approval / pickup
             * branch assignment.
             */
            $originBranchId =
                (int) $shipment->origin_branch_id;

            if ($originBranchId <= 0) {

                throw ValidationException::withMessages([
                    'shipment_id' => [
                        'Shipment does not have an origin branch.',
                    ],
                ]);
            }

            /*
             * Prevent duplicate active attachment.
             */
            $existing = PickupRequestShipment::query()
                ->where(
                    'shipment_id',
                    $shipment->id
                )
                ->whereNull('removed_at')
                ->whereHas(
                    'pickupRequest',
                    function ($query) {
                        $query->whereIn(
                            'status',
                            PickupStatus::acceptingShipments()
                        );
                    }
                )
                ->latest('id')
                ->first();

            if ($existing) {
                return $existing->load([
                    'pickupRequest',
                    'shipment',
                ]);
            }

            /*
             * Find an OPEN pickup request for the SAME:
             *
             * merchant
             * origin branch
             * origin sub branch
             * pickup location
             *
             * This is the key to your dynamic pickup flow.
             */
            $pickupRequest = PickupRequest::query()
                ->where(
                    'merchant_id',
                    $shipment->merchant_id
                )
                ->where(
                    'branch_id',
                    $shipment->origin_branch_id
                )
                ->where(
                    'sub_branch_id',
                    $shipment->origin_sub_branch_id
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

            /*
             * No open pickup request.
             *
             * Create one.
             */
            if (! $pickupRequest) {

                $pickupRequest = $this->createRequest(
                    shipment: $shipment,
                    userId: $userId
                );
            }

            /*
             * Attach shipment.
             */
            $item = PickupRequestShipment::query()
                ->create([
                    'pickup_request_id' =>
                        $pickupRequest->id,

                    'shipment_id' =>
                        $shipment->id,

                    'added_at' =>
                        now(),

                    'added_by' =>
                        $userId,

                    'status' =>
                        PickupShipmentStatus::PENDING,

                    'remarks' =>
                        $remarks,
                ]);

            /*
             * Recalculate parcel count.
             */
            $this->refreshParcelQuantity(
                $pickupRequest
            );

            /*
             * IMPORTANT:
             *
             * If rider has already been assigned,
             * the newly added shipment must immediately
             * become PICKUP_ASSIGNED.
             */
            if (
                in_array(
                    $pickupRequest->status,
                    [
                        PickupStatus::ASSIGNED,
                        PickupStatus::ON_THE_WAY,
                        PickupStatus::ARRIVED,
                    ],
                    true
                )
            ) {

                if (
                    $shipment->status ===
                    ShipmentStatus::AWAITING_PICKUP
                ) {

                    $shipment->update([
                        'status' =>
                            ShipmentStatus::PICKUP_ASSIGNED,

                        'merchant_status' =>
                            ShipmentStatus::PICKUP_ASSIGNED,
                    ]);

                    $this->trackingService->record(
                        $shipment,
                        ShipmentStatus::PICKUP_ASSIGNED,
                        "Shipment added to active pickup request {$pickupRequest->request_number}.",
                        $userId
                    );
                }
            }

            /*
             * Normal case.
             */
            elseif (
                $shipment->status ===
                ShipmentStatus::AWAITING_PICKUP
            ) {

                $this->trackingService->record(
                    $shipment,
                    ShipmentStatus::AWAITING_PICKUP,
                    "Shipment added to pickup request {$pickupRequest->request_number}.",
                    $userId
                );
            }

            return $item->fresh([
                'pickupRequest',
                'shipment',
            ]);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Create Pickup Request
    |--------------------------------------------------------------------------
    */

    private function createRequest(
        Shipment $shipment,
        ?int $userId = null
    ): PickupRequest {

        $pickupRequest = PickupRequest::query()
            ->create([
                'request_number' => null,

                'merchant_id' =>
                    $shipment->merchant_id,

                /*
                 * ORIGIN branch.
                 */
                'branch_id' =>
                    $shipment->origin_branch_id,

                'sub_branch_id' =>
                    $shipment->origin_sub_branch_id,

                /*
                 * Explicit pickup target.
                 */
                'pickup_branch_id' =>
                    $shipment->origin_branch_id,

                'pickup_sub_branch_id' =>
                    $shipment->origin_sub_branch_id,

                /*
                 * Legacy field.
                 *
                 * Do not use.
                 */
                'shipment_id' =>
                    null,

                'pickup_location_id' =>
                    $shipment->pickup_location_id,

                'pickup_name' =>
                    $shipment->sender_name,

                'pickup_phone' =>
                    $shipment->sender_phone,

                'pickup_address' =>
                    $shipment->sender_address,

                'pickup_city' =>
                    $shipment->sender_city,

                'pickup_area' =>
                    $shipment->sender_area,

                'pickup_lat' =>
                    $shipment->pickup_lat,

                'pickup_lng' =>
                    $shipment->pickup_lng,

                'preferred_pickup_at' =>
                    null,

                'parcel_quantity' =>
                    0,

                'status' =>
                    PickupStatus::REQUESTED,

                'requested_at' =>
                    now(),

                'accepted_at' =>
                    null,

                'assigned_at' =>
                    null,

                'assigned_by' =>
                    null,

                'assigned_to' =>
                    null,

                'picked_up_by' =>
                    null,

                'picked_up_at' =>
                    null,

                'received_at_origin_at' =>
                    null,

                'failed_at' =>
                    null,

                'failed_reason' =>
                    null,

                'arrived_at' =>
                    null,

                'completed_at' =>
                    null,

                'cancelled_at' =>
                    null,

                'remarks' =>
                    null,
            ]);

        $pickupRequest->update([
            'request_number' =>
                $this->numbers->generate(
                    $pickupRequest->id
                ),
        ]);

        return $pickupRequest->fresh();
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

            'shipments.shipment',
            'shipments.addedBy',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Assign Rider
    |--------------------------------------------------------------------------
    */

    public function assign(
        PickupRequest $pickup,
        User $staff,
        User $assignedBy
    ): PickupRequest {

        return DB::transaction(function () use (
            $pickup,
            $staff,
            $assignedBy
        ) {

            $pickup = PickupRequest::query()
                ->whereKey($pickup->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                ! PickupStatus::canAssign(
                    (string) $pickup->status
                )
            ) {

                throw ValidationException::withMessages([
                    'status' => [
                        "Pickup request cannot be assigned while it is {$pickup->status}.",
                    ],
                ]);
            }

            /*
             * Staff must belong to target branch.
             */
            $this->validateStaffForPickup(
                $pickup,
                $staff
            );

            $pickup->update([
                'assigned_to' =>
                    $staff->id,

                'assigned_by' =>
                    $assignedBy->id,

                'assigned_at' =>
                    now(),

                'accepted_at' =>
                    $pickup->accepted_at ?? now(),

                'status' =>
                    PickupStatus::ASSIGNED,
            ]);

            /*
             * All current pending shipments become
             * PICKUP_ASSIGNED.
             */
            $items = PickupRequestShipment::query()
                ->where(
                    'pickup_request_id',
                    $pickup->id
                )
                ->whereNull('removed_at')
                ->where(
                    'status',
                    PickupShipmentStatus::PENDING
                )
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
                    ShipmentStatus::AWAITING_PICKUP
                ) {

                    $shipment->update([
                        'status' =>
                            ShipmentStatus::PICKUP_ASSIGNED,

                        'merchant_status' =>
                            ShipmentStatus::PICKUP_ASSIGNED,
                    ]);

                    $this->trackingService->record(
                        $shipment,
                        ShipmentStatus::PICKUP_ASSIGNED,
                        "Pickup request {$pickup->request_number} assigned to rider.",
                        $assignedBy->id
                    );
                }
            }

            return $this->get($pickup);
        });
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

        return DB::transaction(function () use (
            $pickup,
            $user
        ) {

            $pickup = PickupRequest::query()
                ->whereKey($pickup->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                ! PickupStatus::canStart(
                    (string) $pickup->status
                )
            ) {

                throw ValidationException::withMessages([
                    'status' => [
                        "Pickup cannot start while it is {$pickup->status}.",
                    ],
                ]);
            }

            $this->ensureAssignedUser(
                $pickup,
                $user
            );

            $pickup->update([
                'status' =>
                    PickupStatus::ON_THE_WAY,
            ]);

            return $this->get($pickup);
        });
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

        return DB::transaction(function () use (
            $pickup,
            $user
        ) {

            $pickup = PickupRequest::query()
                ->whereKey($pickup->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                ! PickupStatus::canArrive(
                    (string) $pickup->status
                )
            ) {

                throw ValidationException::withMessages([
                    'status' => [
                        "Pickup cannot be marked arrived while it is {$pickup->status}.",
                    ],
                ]);
            }

            $this->ensureAssignedUser(
                $pickup,
                $user
            );

            $pickup->update([
                'status' =>
                    PickupStatus::ARRIVED,

                'arrived_at' =>
                    now(),
            ]);

            return $this->get($pickup);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Collect Shipment
    |--------------------------------------------------------------------------
    */

    public function collectShipment(
        PickupRequest $pickup,
        Shipment $shipment,
        User $user,
        ?string $remarks = null
    ): PickupRequestShipment {

        return DB::transaction(function () use (
            $pickup,
            $shipment,
            $user,
            $remarks
        ) {

            $pickup = PickupRequest::query()
                ->whereKey($pickup->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureAssignedUser(
                $pickup,
                $user
            );

            /*
             * Rider must have reached the merchant.
             */
            if (
                ! in_array(
                    $pickup->status,
                    [
                        PickupStatus::ARRIVED,
                    ],
                    true
                )
            ) {

                throw ValidationException::withMessages([
                    'pickup' => [
                        'Shipment can only be collected after the rider has arrived at the pickup location.',
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
                ->firstOrFail();

            if (
                $item->status ===
                PickupShipmentStatus::COLLECTED
            ) {
                return $item->fresh([
                    'pickupRequest',
                    'shipment',
                ]);
            }

            if (
                $item->status !==
                PickupShipmentStatus::PENDING
            ) {

                throw ValidationException::withMessages([
                    'shipment' => [
                        "Shipment cannot be collected because its pickup status is {$item->status}.",
                    ],
                ]);
            }

            $item->update([
                'status' =>
                    PickupShipmentStatus::COLLECTED,

                'remarks' =>
                    $remarks ?? $item->remarks,
            ]);

            /*
             * Physical collection from store.
             */
            $shipment->update([
                'status' =>
                    ShipmentStatus::PICKED_UP,

                'merchant_status' =>
                    ShipmentStatus::PICKED_UP,

                /*
                 * It has not yet arrived at branch.
                 */
                'current_branch_id' =>
                    $shipment->origin_branch_id,

                'current_sub_branch_id' =>
                    $shipment->origin_sub_branch_id,
            ]);

            $this->trackingService->record(
                $shipment,
                ShipmentStatus::PICKED_UP,
                "Shipment collected under pickup request {$pickup->request_number}.",
                $user->id
            );

            /*
             * DO NOT COMPLETE PICKUP HERE.
             *
             * New shipments may still be added.
             *
             * The pickup request remains ARRIVED.
             */
            return $item->fresh([
                'pickupRequest',
                'shipment',
            ]);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Receive At Origin Branch
    |--------------------------------------------------------------------------
    */

    public function receiveShipment(
        PickupRequest $pickup,
        Shipment $shipment,
        User $staff
    ): PickupRequestShipment {

        return DB::transaction(function () use (
            $pickup,
            $shipment,
            $staff
        ) {

            $pickup = PickupRequest::query()
                ->whereKey($pickup->id)
                ->lockForUpdate()
                ->firstOrFail();

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
                ->firstOrFail();

            if (
                $item->status ===
                PickupShipmentStatus::RECEIVED
            ) {
                return $item->fresh([
                    'pickupRequest',
                    'shipment',
                ]);
            }

            if (
                $item->status !==
                PickupShipmentStatus::COLLECTED
            ) {

                throw ValidationException::withMessages([
                    'shipment' => [
                        'Shipment must be collected before it can be received at the origin branch.',
                    ],
                ]);
            }

            $this->ensureOriginBranchStaff(
                $shipment,
                $staff
            );

            /*
             * Mark pickup item received.
             */
            $item->update([
                'status' =>
                    PickupShipmentStatus::RECEIVED,
            ]);

            /*
             * NOW the shipment physically exists
             * at the origin branch.
             */
            $this->shipmentService->updateStatus(
                $shipment,
                CourierStatus::AT_ORIGIN_BRANCH,
                $staff->id,
                'Shipment physically received and scanned at origin branch.'
            );

            /*
             * Keep current location accurate.
             */
            $shipment->update([
                'current_branch_id' =>
                    $shipment->origin_branch_id,

                'current_sub_branch_id' =>
                    $shipment->origin_sub_branch_id,
            ]);

            /*
             * Only close pickup when every active
             * shipment has reached the branch.
             *
             * This does NOT happen merely because
             * the rider collected everything.
             */
            $remaining = PickupRequestShipment::query()
                ->where(
                    'pickup_request_id',
                    $pickup->id
                )
                ->whereNull('removed_at')
                ->whereNotIn(
                    'status',
                    [
                        PickupShipmentStatus::RECEIVED,
                        PickupShipmentStatus::FAILED,
                    ]
                )
                ->exists();

            if (! $remaining) {

                $pickup->update([
                    'status' =>
                        PickupStatus::COMPLETED,

                    'received_at_origin_at' =>
                        now(),

                    'completed_at' =>
                        now(),
                ]);
            }

            return $item->fresh([
                'pickupRequest',
                'shipment',
            ]);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Fail Pickup
    |--------------------------------------------------------------------------
    */

    public function fail(
        PickupRequest $pickup,
        User $user,
        string $reason
    ): PickupRequest {

        return DB::transaction(function () use (
            $pickup,
            $user,
            $reason
        ) {

            $pickup = PickupRequest::query()
                ->whereKey($pickup->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                ! PickupStatus::canFail(
                    (string) $pickup->status
                )
            ) {

                throw ValidationException::withMessages([
                    'status' => [
                        "Pickup cannot be failed while it is {$pickup->status}.",
                    ],
                ]);
            }

            $pickup->update([
                'status' =>
                    PickupStatus::FAILED,

                'failed_at' =>
                    now(),

                'failed_reason' =>
                    $reason,
            ]);

            $items = PickupRequestShipment::query()
                ->where(
                    'pickup_request_id',
                    $pickup->id
                )
                ->whereNull('removed_at')
                ->where(
                    'status',
                    PickupShipmentStatus::PENDING
                )
                ->with('shipment')
                ->lockForUpdate()
                ->get();

            foreach ($items as $item) {

                $shipment = $item->shipment;

                if (! $shipment) {
                    continue;
                }

                $item->update([
                    'status' =>
                        PickupShipmentStatus::FAILED,

                    'remarks' =>
                        $reason,
                ]);

                if (
                    in_array(
                        $shipment->status,
                        [
                            ShipmentStatus::AWAITING_PICKUP,
                            ShipmentStatus::PICKUP_ASSIGNED,
                        ],
                        true
                    )
                ) {

                    $shipment->update([
                        'status' =>
                            ShipmentStatus::AWAITING_PICKUP,

                        'merchant_status' =>
                            ShipmentStatus::AWAITING_PICKUP,
                    ]);

                    $this->trackingService->record(
                        $shipment,
                        ShipmentStatus::AWAITING_PICKUP,
                        "Pickup failed: {$reason}",
                        $user->id
                    );
                }
            }

            return $this->get($pickup);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Remove Shipment
    |--------------------------------------------------------------------------
    */

    public function removeShipment(
        PickupRequest $pickup,
        Shipment $shipment,
        User $user
    ): PickupRequestShipment {

        return DB::transaction(function () use (
            $pickup,
            $shipment,
            $user
        ) {

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
                ->firstOrFail();

            if (
                $item->status !==
                PickupShipmentStatus::PENDING
            ) {

                throw ValidationException::withMessages([
                    'shipment' => [
                        'Only pending shipments can be removed from a pickup.',
                    ],
                ]);
            }

            $item->update([
                'removed_at' =>
                    now(),

                'removed_by' =>
                    $user->id,

                'status' =>
                    PickupShipmentStatus::REMOVED,
            ]);

            $this->refreshParcelQuantity(
                $pickup
            );

            return $item->fresh([
                'pickupRequest',
                'shipment',
            ]);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Refresh Parcel Quantity
    |--------------------------------------------------------------------------
    */

    private function refreshParcelQuantity(
        PickupRequest $pickup
    ): void {

        $count = PickupRequestShipment::query()
            ->where(
                'pickup_request_id',
                $pickup->id
            )
            ->whereNull('removed_at')
            ->count();

        $pickup->update([
            'parcel_quantity' => $count,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Shipment
    |--------------------------------------------------------------------------
    */

    private function validateShipmentForPickup(
        Shipment $shipment
    ): void {

        if (
            ! in_array(
                $shipment->status,
                [
                    ShipmentStatus::AWAITING_PICKUP,
                    ShipmentStatus::PICKUP_ASSIGNED,
                ],
                true
            )
        ) {

            throw ValidationException::withMessages([
                'shipment_id' => [
                    "Shipment {$shipment->tracking_number} is not available for pickup.",
                ],
            ]);
        }

        if (! $shipment->merchant_id) {

            throw ValidationException::withMessages([
                'shipment_id' => [
                    'Shipment does not have a merchant.',
                ],
            ]);
        }

        if (! $shipment->origin_branch_id) {

            throw ValidationException::withMessages([
                'shipment_id' => [
                    'Shipment does not have an origin branch.',
                ],
            ]);
        }

        if (! $shipment->pickup_location_id) {

            throw ValidationException::withMessages([
                'shipment_id' => [
                    'Shipment does not have a pickup location.',
                ],
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Staff
    |--------------------------------------------------------------------------
    */

    private function validateStaffForPickup(
        PickupRequest $pickup,
        User $staff
    ): void {

        $targetBranchId =
            (int) (
                $pickup->pickup_branch_id
                ??
                $pickup->branch_id
            );

        if ($targetBranchId <= 0) {

            throw ValidationException::withMessages([
                'staff_id' => [
                    'Pickup request does not have a target branch.',
                ],
            ]);
        }

        if (
            (int) $staff->branch_id !==
            $targetBranchId
        ) {

            throw ValidationException::withMessages([
                'staff_id' => [
                    'Selected staff does not belong to the pickup branch.',
                ],
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Assigned User
    |--------------------------------------------------------------------------
    */

    private function ensureAssignedUser(
        PickupRequest $pickup,
        User $user
    ): void {

        if (
            $user->isSuperAdmin()
            ||
            $user->hasRole('main_admin')
            ||
            $user->hasRole('branch_manager')
            ||
            $user->hasRole('sub_branch_manager')
        ) {
            return;
        }

        if (
            (int) $pickup->assigned_to !==
            (int) $user->id
        ) {

            abort(
                403,
                'You are not assigned to this pickup request.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Origin Branch Staff
    |--------------------------------------------------------------------------
    */

    private function ensureOriginBranchStaff(
        Shipment $shipment,
        User $staff
    ): void {

        if (
            $staff->isSuperAdmin()
            ||
            $staff->hasRole('main_admin')
        ) {
            return;
        }

        $staffBranch =
            (int) $staff->branch_id;

        $originBranch =
            (int) $shipment->origin_branch_id;

        $originSubBranch =
            (int) $shipment->origin_sub_branch_id;

        if (
            $staffBranch === $originBranch
            ||
            (
                $originSubBranch > 0
                &&
                $staffBranch === $originSubBranch
            )
        ) {
            return;
        }

        abort(
            403,
            'You are not authorized to receive this shipment at the origin branch.'
        );
    }
}