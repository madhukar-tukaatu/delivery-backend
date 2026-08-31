<?php

declare(strict_types=1);

namespace Modules\Pickup\Services;

use App\Support\CourierStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantPickupLocation;
use Modules\Pickup\Models\PickupRequest;
use Modules\Pickup\Support\PickupStatus;
use Modules\Shipment\Models\Shipment;

final class GatewayPickupService
{
    /**
     * Create or reuse a pickup request.
     *
     * IMPORTANT:
     *
     * The same merchant + pickup location may continue adding
     * shipments to the same pickup while:
     *
     * requested
     * assigned
     * started
     * arrived
     *
     * The pickup becomes closed only after:
     *
     * completed
     * failed
     */
    public function create(
        int $merchantId,
        array $data
    ): PickupRequest {

        $merchant =
            Merchant::query()
                ->find($merchantId);

        if (! $merchant) {
            throw ValidationException::withMessages([
                'merchant' => [
                    'Authenticated merchant was not found.',
                ],
            ]);
        }

        if ($merchant->status !== 'active') {
            throw ValidationException::withMessages([
                'merchant' => [
                    'Merchant account is not active.',
                ],
            ]);
        }

        return DB::transaction(
            function () use (
                $merchant,
                $data
            ): PickupRequest {

                /*
                |--------------------------------------------------------------------------
                | Pickup location
                |--------------------------------------------------------------------------
                */

                $pickupLocation =
                    MerchantPickupLocation::query()
                        ->where(
                            'id',
                            $data['pickup_location_id']
                        )
                        ->where(
                            'merchant_id',
                            $merchant->id
                        )
                        ->first();

                if (! $pickupLocation) {
                    throw ValidationException::withMessages([
                        'pickup_location_id' => [
                            'Pickup location does not belong to this merchant.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Find eligible shipments
                |--------------------------------------------------------------------------
                */

                $shipments =
                    $this->findEligibleShipments(
                        merchantId:
                            $merchant->id,

                        pickupLocationId:
                            $pickupLocation->id
                    );

                if ($shipments->isEmpty()) {
                    throw ValidationException::withMessages([
                        'pickup' => [
                            'There are no shipments awaiting pickup for this pickup location.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Find reusable pickup
                |--------------------------------------------------------------------------
                |
                | VERY IMPORTANT:
                |
                | This intentionally includes:
                |
                | requested
                | assigned
                | started
                | arrived
                |
                | Therefore:
                |
                | shipment A
                |   ↓
                | pickup created
                |   ↓
                | rider assigned
                |   ↓
                | shipment B created
                |   ↓
                | pickup API called
                |   ↓
                | shipment B joins SAME pickup
                |
                */

                $pickup =
                    PickupRequest::query()
                        ->where(
                            'pickup_requests.merchant_id',
                            $merchant->id
                        )
                        ->where(
                            'pickup_requests.pickup_location_id',
                            $pickupLocation->id
                        )
                        ->whereIn(
                            'pickup_requests.status',
                            PickupStatus::acceptingShipments()
                        )
                        ->lockForUpdate()
                        ->latest('pickup_requests.id')
                        ->first();

                /*
                |--------------------------------------------------------------------------
                | Create new pickup if necessary
                |--------------------------------------------------------------------------
                */

                if (! $pickup) {

                    $pickup =
                        $this->createPickupRequest(
                            merchant:
                                $merchant,

                            pickupLocation:
                                $pickupLocation,

                            data:
                                $data
                        );

                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | Update merchant preferences
                    |--------------------------------------------------------------------------
                    */

                    if (
                        ! empty(
                            $data['preferred_pickup_at']
                        )
                    ) {
                        $pickup->preferred_pickup_at =
                            $data['preferred_pickup_at'];
                    }

                    if (
                        ! empty(
                            $data['remarks']
                        )
                    ) {
                        $pickup->remarks =
                            $data['remarks'];
                    }

                    $pickup->save();
                }

                /*
                |--------------------------------------------------------------------------
                | Attach shipments
                |--------------------------------------------------------------------------
                */

                foreach ($shipments as $shipment) {

                    $this->attachShipmentToPickup(
                        pickup:
                            $pickup,

                        shipment:
                            $shipment
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Recalculate parcel count
                |--------------------------------------------------------------------------
                */

                $pickup->parcel_quantity =
                    $pickup
                        ->activeShipments()
                        ->count();

                $pickup->save();

                /*
                |--------------------------------------------------------------------------
                | Return complete pickup
                |--------------------------------------------------------------------------
                */

                return $pickup->fresh([
                    'merchant',
                    'pickupLocation',
                    'branch',
                    'subBranch',
                    'pickupBranch',
                    'pickupSubBranch',
                    'assignedStaff',
                    'assignedBy',
                    'pickedUpBy',
                    'shipments.shipment',
                ]);
            }
        );
    }

    /**
     * Find shipments which can be attached.
     *
     * A shipment is eligible when:
     *
     * - belongs to merchant
     * - belongs to pickup location
     * - awaiting pickup
     * - not already attached to active pickup
     */
    private function findEligibleShipments(
        int $merchantId,
        int $pickupLocationId
    ) {

        return Shipment::query()
            ->where(
                'shipments.merchant_id',
                $merchantId
            )
            ->where(
                'shipments.pickup_location_id',
                $pickupLocationId
            )
            ->where(
                'shipments.status',
                CourierStatus::AWAITING_PICKUP
            )
            ->whereDoesntHave(
                'pickupRequests',
                function ($query) {

                    /*
                    |--------------------------------------------------------------------------
                    | IMPORTANT
                    |--------------------------------------------------------------------------
                    |
                    | Explicit table name prevents:
                    |
                    | SQLSTATE[23000]
                    | Column 'status' is ambiguous
                    |
                    */

                    $query->whereIn(
                        'pickup_requests.status',
                        PickupStatus::active()
                    );
                }
            )
            ->lockForUpdate()
            ->get();
    }

    /**
     * Create pickup request.
     */
    private function createPickupRequest(
        Merchant $merchant,
        MerchantPickupLocation $pickupLocation,
        array $data
    ): PickupRequest {

        $pickupName =
            $pickupLocation->name
            ??
            $pickupLocation->pickup_name
            ??
            $merchant->name
            ??
            'Merchant Pickup';

        $pickupPhone =
            $pickupLocation->phone
            ??
            $pickupLocation->pickup_phone
            ??
            $merchant->phone
            ??
            null;

        $pickupEmail =
            $pickupLocation->email
            ??
            $pickupLocation->pickup_email
            ??
            $merchant->email
            ??
            null;

        $pickupAddress =
            $pickupLocation->address
            ??
            $pickupLocation->pickup_address
            ??
            null;

        $pickupCity =
            $pickupLocation->city
            ??
            null;

        $pickupArea =
            $pickupLocation->area
            ??
            null;

        $pickupData = [

            'merchant_id' =>
                $merchant->id,

            'pickup_location_id' =>
                $pickupLocation->id,

            'pickup_name' =>
                $pickupName,

            'pickup_phone' =>
                $pickupPhone,

            'pickup_email' =>
                $pickupEmail,

            'pickup_address' =>
                $pickupAddress,

            'pickup_city' =>
                $pickupCity,

            'pickup_area' =>
                $pickupArea,

            'status' =>
                PickupStatus::REQUESTED,

            'requested_at' =>
                now(),

            'preferred_pickup_at' =>
                $data['preferred_pickup_at']
                ??
                null,

            'remarks' =>
                $data['remarks']
                ??
                null,

            'parcel_quantity' =>
                0,
        ];

        $columns =
            $this->pickupRequestColumns();

        if (
            array_key_exists(
                'pickup_lat',
                $columns
            )
        ) {
            $pickupData['pickup_lat'] =
                $this->getLocationValue(
                    $pickupLocation,
                    [
                        'latitude',
                        'lat',
                        'pickup_lat',
                    ]
                );
        }

        if (
            array_key_exists(
                'pickup_lng',
                $columns
            )
        ) {
            $pickupData['pickup_lng'] =
                $this->getLocationValue(
                    $pickupLocation,
                    [
                        'longitude',
                        'lng',
                        'pickup_lng',
                    ]
                );
        }

        $pickupData =
            array_intersect_key(
                $pickupData,
                $columns
            );

        return PickupRequest::query()
            ->create($pickupData);
    }

    /**
     * Attach shipment to pickup and update shipment lifecycle.
     */
    private function attachShipmentToPickup(
        PickupRequest $pickup,
        Shipment $shipment
    ): void {

        /*
        |--------------------------------------------------------------------------
        | Prevent duplicate attachment
        |--------------------------------------------------------------------------
        */

        $existing =
            $pickup->shipments()
                ->where(
                    'shipment_id',
                    $shipment->id
                )
                ->whereNull('removed_at')
                ->first();

        if ($existing) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Create pivot
        |--------------------------------------------------------------------------
        */

        $pickup->shipments()
            ->create([
                'shipment_id' =>
                    $shipment->id,
            ]);

        /*
        |--------------------------------------------------------------------------
        | Shipment lifecycle
        |--------------------------------------------------------------------------
        |
        | If rider is already assigned, the newly added shipment must
        | immediately become pickup_assigned.
        |
        | If pickup is still requested, leave shipment as
        | awaiting_pickup.
        |
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
        ) {

            $oldStatus =
                $shipment->status;

            $shipment->status =
                CourierStatus::PICKUP_ASSIGNED;

            $shipment->merchant_status =
                CourierStatus::merchantStatus(
                    CourierStatus::PICKUP_ASSIGNED
                );

            $shipment->save();

            $this->createTrackingEvent(
                shipment:
                    $shipment,

                oldStatus:
                    $oldStatus,

                newStatus:
                    CourierStatus::PICKUP_ASSIGNED,

                description:
                    'Shipment added to an active pickup request. Rider is already assigned.'
            );
        }
    }

    /**
     * Create tracking event.
     */
    private function createTrackingEvent(
        Shipment $shipment,
        ?string $oldStatus,
        string $newStatus,
        string $description
    ): void {

        $table = 'tracking_events';

        if (
            ! DB::getSchemaBuilder()
                ->hasTable($table)
        ) {
            return;
        }

        $data = [

            'shipment_id' =>
                $shipment->id,

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
                null,

            'created_at' =>
                now(),

            'updated_at' =>
                now(),
        ];

        $columns =
            DB::getSchemaBuilder()
                ->getColumnListing($table);

        $data =
            array_intersect_key(
                $data,
                array_flip($columns)
            );

        DB::table($table)
            ->insert($data);
    }

    private function getLocationValue(
        MerchantPickupLocation $location,
        array $attributes
    ) {

        foreach ($attributes as $attribute) {

            if (
                isset(
                    $location->{$attribute}
                )
            ) {
                return $location->{$attribute};
            }
        }

        return null;
    }

    private function pickupRequestColumns(): array
    {
        return array_flip(
            DB::getSchemaBuilder()
                ->getColumnListing(
                    'pickup_requests'
                )
        );
    }

    /**
     * Get merchant pickup request.
     */
    public function findForMerchant(
        int $merchantId,
        string $requestNumber
    ): PickupRequest {

        return PickupRequest::query()
            ->where(
                'pickup_requests.merchant_id',
                $merchantId
            )
            ->where(
                'pickup_requests.request_number',
                $requestNumber
            )
            ->with([
                'pickupLocation',
                'pickupBranch',
                'pickupSubBranch',
                'assignedStaff',
                'assignedBy',
                'pickedUpBy',
                'shipments.shipment',
            ])
            ->firstOrFail();
    }
}