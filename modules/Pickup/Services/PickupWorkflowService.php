<?php

namespace Modules\Pickup\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Pickup\Enums\PickupRequestStatus;
use Modules\Pickup\Models\PickupRequest;
use Modules\Shipment\Enums\ShipmentStatus;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Services\PickupRequestNumberService;
use Modules\Tracking\Services\TrackingService;

class PickupWorkflowService
{
    public function __construct(
        private readonly PickupRequestNumberService $numbers,
        private readonly TrackingService $trackingService,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Create Pickup Request
    |--------------------------------------------------------------------------
    */

    public function create(
        User $user,
        array $data
    ): PickupRequest {

        $merchant = $user->merchant;

        return DB::transaction(
            function () use (
                $user,
                $merchant,
                $data
            ) {

                $shipments = Shipment::query()
                    ->where('merchant_id', $merchant->id)
                    ->whereIn(
                        'id',
                        $data['shipment_ids']
                    )
                    ->lockForUpdate()
                    ->get();

                if (
                    $shipments->count()
                    !== count(
                        array_unique(
                            $data['shipment_ids']
                        )
                    )
                ) {
                    throw ValidationException::withMessages([
                        'shipment_ids' =>
                            'One or more shipments do not belong to this merchant.',
                    ]);
                }

                foreach ($shipments as $shipment) {
                    $this->validateShipmentForPickup(
                        $shipment
                    );
                }

                $firstShipment =
                    $shipments->first();

                $pickup = PickupRequest::create([

                    'merchant_id' =>
                        $merchant->id,

                    'request_number' =>
                        $this->numbers->generate(),

                    'branch_id' =>
                        $firstShipment->origin_branch_id,

                    'sub_branch_id' =>
                        $firstShipment->origin_sub_branch_id,

                    'pickup_name' =>
                        $firstShipment->sender_name,

                    'pickup_phone' =>
                        $firstShipment->sender_phone,

                    'pickup_address' =>
                        $firstShipment->sender_address,

                    'pickup_city' =>
                        $firstShipment->sender_city,

                    'pickup_area' =>
                        $firstShipment->sender_area,

                    'pickup_lat' =>
                        $firstShipment->pickup_lat,

                    'pickup_lng' =>
                        $firstShipment->pickup_lng,

                    'preferred_pickup_at' =>
                        $data['preferred_pickup_at']
                        ?? null,

                    'remarks' =>
                        $data['remarks']
                        ?? null,

                    'status' =>
                        PickupRequestStatus::REQUESTED,

                    'requested_at' =>
                        now(),
                ]);

                $this->attachShipments(
                    $pickup,
                    $shipments,
                    $user
                );

                return $pickup->fresh([
                    'merchant',
                    'branch',
                    'subBranch',
                    'shipments',
                ]);
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Add Shipments
    |--------------------------------------------------------------------------
    */

    public function addShipments(
        PickupRequest $pickup,
        User $user,
        array $shipmentIds,
        ?string $remarks = null
    ): PickupRequest {

        return DB::transaction(
            function () use (
                $pickup,
                $user,
                $shipmentIds,
                $remarks
            ) {

                $pickup = PickupRequest::query()
                    ->whereKey($pickup->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    !PickupRequestStatus::canAddShipments(
                        $pickup->status
                    )
                ) {
                    throw ValidationException::withMessages([
                        'pickup_request' =>
                            'Shipments can no longer be added to this pickup request.',
                    ]);
                }

                $shipments = Shipment::query()
                    ->where('merchant_id', $pickup->merchant_id)
                    ->whereIn('id', $shipmentIds)
                    ->lockForUpdate()
                    ->get();

                if (
                    $shipments->count()
                    !== count(
                        array_unique($shipmentIds)
                    )
                ) {
                    throw ValidationException::withMessages([
                        'shipment_ids' =>
                            'One or more shipments are invalid for this pickup request.',
                    ]);
                }

                foreach ($shipments as $shipment) {

                    $this->validateShipmentForPickup(
                        $shipment
                    );

                    if (
                        (int) $shipment->origin_branch_id
                        !== (int) $pickup->branch_id
                        ||
                        (int) $shipment->origin_sub_branch_id
                        !== (int) $pickup->sub_branch_id
                    ) {
                        throw ValidationException::withMessages([
                            'shipment_ids' =>
                                "Shipment {$shipment->tracking_number} belongs to a different pickup branch.",
                        ]);
                    }

                    $alreadyAttached =
                        $pickup->shipments()
                            ->where(
                                'shipments.id',
                                $shipment->id
                            )
                            ->exists();

                    if ($alreadyAttached) {
                        continue;
                    }

                    $pickup->shipments()->attach(
                        $shipment->id,
                        [
                            'added_at' =>
                                now(),

                            'added_by' =>
                                $user->id,

                            'collection_status' =>
                                'pending',

                            'remarks' =>
                                $remarks,
                        ]
                    );

                    $this->trackingService->record(
                        $shipment,
                        ShipmentStatus::AWAITING_PICKUP,
                        "Shipment added to pickup request {$pickup->request_number}.",
                        $user->id
                    );
                }

                return $pickup->fresh([
                    'merchant',
                    'branch',
                    'subBranch',
                    'shipments',
                ]);
            }
        );
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

        return DB::transaction(
            function () use (
                $pickup,
                $staff,
                $assignedBy
            ) {

                $pickup->update([
                    'assigned_to' =>
                        $staff->id,

                    'assigned_at' =>
                        now(),

                    'status' =>
                        PickupRequestStatus::ASSIGNED,
                ]);

                $shipments =
                    $pickup->shipments()
                        ->wherePivot(
                            'removed_at',
                            null
                        )
                        ->get();

                foreach ($shipments as $shipment) {

                    if (
                        $shipment->status
                        === ShipmentStatus::AWAITING_PICKUP
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

                return $pickup->fresh([
                    'shipments',
                    'assignedStaff',
                ]);
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Rider Arrived
    |--------------------------------------------------------------------------
    */

    public function riderArrived(
        PickupRequest $pickup,
        User $user
    ): PickupRequest {

        $pickup->update([
            'status' =>
                PickupRequestStatus::RIDER_ARRIVED,

            'rider_arrived_at' =>
                now(),
        ]);

        return $pickup->fresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Start Collection
    |--------------------------------------------------------------------------
    */

    public function startCollection(
        PickupRequest $pickup,
        User $user
    ): PickupRequest {

        $pickup->update([
            'status' =>
                PickupRequestStatus::COLLECTING,

            'collection_started_at' =>
                now(),
        ]);

        return $pickup->fresh([
            'shipments',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Complete Pickup
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
            ) {

                $pickup = PickupRequest::query()
                    ->whereKey($pickup->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $shipments =
                    $pickup->shipments()
                        ->wherePivot(
                            'removed_at',
                            null
                        )
                        ->get();

                foreach ($shipments as $shipment) {

                    $shipment->update([
                        'status' =>
                            ShipmentStatus::PICKED_UP,

                        'merchant_status' =>
                            ShipmentStatus::PICKED_UP,

                        'current_branch_id' =>
                            $shipment->origin_branch_id,

                        'current_sub_branch_id' =>
                            $shipment->origin_sub_branch_id,
                    ]);

                    $pickup->shipments()->updateExistingPivot(
                        $shipment->id,
                        [
                            'collection_status' =>
                                'collected',

                            'collected_at' =>
                                now(),

                            'collected_by' =>
                                $user->id,
                        ]
                    );

                    $this->trackingService->record(
                        $shipment,
                        ShipmentStatus::PICKED_UP,
                        "Shipment collected under pickup request {$pickup->request_number}.",
                        $user->id
                    );
                }

                $pickup->update([
                    'status' =>
                        PickupRequestStatus::COMPLETED,

                    'completed_at' =>
                        now(),
                ]);

                return $pickup->fresh([
                    'shipments',
                ]);
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    private function validateShipmentForPickup(
        Shipment $shipment
    ): void {

        if (
            !in_array(
                $shipment->status,
                [
                    ShipmentStatus::AWAITING_PICKUP,
                    ShipmentStatus::PICKUP_ASSIGNED,
                ],
                true
            )
        ) {
            throw ValidationException::withMessages([
                'shipment_ids' =>
                    "Shipment {$shipment->tracking_number} is not available for pickup.",
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Attach
    |--------------------------------------------------------------------------
    */

    private function attachShipments(
        PickupRequest $pickup,
        $shipments,
        User $user
    ): void {

        foreach ($shipments as $shipment) {

            $pickup->shipments()->attach(
                $shipment->id,
                [
                    'added_at' =>
                        now(),

                    'added_by' =>
                        $user->id,

                    'collection_status' =>
                        'pending',
                ]
            );
        }
    }
}