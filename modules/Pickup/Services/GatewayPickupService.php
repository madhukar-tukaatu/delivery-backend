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
     * Create a pickup request from an external Store Manager.
     *
     * Shipment creation and pickup creation are deliberately separate.
     *
     * POST /gateway/shipments
     *      ↓
     * shipment = awaiting_pickup
     *
     * POST /gateway/pickups
     *      ↓
     * pickup request created
     *      ↓
     * eligible shipments attached
     */
    public function create(
        int $merchantId,
        array $data
    ): PickupRequest {

        $merchant = Merchant::query()
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

                $pickupLocation = MerchantPickupLocation::query()
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
                | Find eligible shipments FIRST
                |--------------------------------------------------------------------------
                |
                | We should never create an empty pickup request.
                |
                */

                $shipments = $this->findEligibleShipments(
                    merchantId: $merchant->id,
                    pickupLocationId: $pickupLocation->id,
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
                | Only a pickup which is still waiting to be processed can
                | accept additional shipments.
                |
                | Once a rider has been assigned / started / arrived,
                | the pickup batch should be frozen.
                |
                */

                $pickup = PickupRequest::query()
                    ->where(
                        'merchant_id',
                        $merchant->id
                    )
                    ->where(
                        'pickup_location_id',
                        $pickupLocation->id
                    )
                    ->whereIn(
                        'status',
                        $this->reusablePickupStatuses()
                    )
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                /*
                |--------------------------------------------------------------------------
                | Create new pickup
                |--------------------------------------------------------------------------
                */

                if (! $pickup) {

                    $pickup = $this->createPickupRequest(
                        merchant: $merchant,
                        pickupLocation: $pickupLocation,
                        data: $data,
                    );

                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | Existing reusable pickup
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

                $this->attachShipments(
                    pickup: $pickup,
                    shipments: $shipments,
                );

                /*
                |--------------------------------------------------------------------------
                | Recalculate quantity
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
                    'shipments.shipment',
                    'pickupBranch',
                    'pickupSubBranch',
                    'assignedStaff',
                ]);
            }
        );
    }

    /**
     * Find shipments eligible for a pickup request.
     *
     * Conditions:
     *
     * - belongs to merchant
     * - belongs to pickup location
     * - awaiting pickup
     * - not already attached to an active pickup
     */
    private function findEligibleShipments(
        int $merchantId,
        int $pickupLocationId
    ) {

        return Shipment::query()
            ->where(
                'merchant_id',
                $merchantId
            )
            ->where(
                'pickup_location_id',
                $pickupLocationId
            )
            ->where(
                'status',
                CourierStatus::AWAITING_PICKUP
            )
            ->whereDoesntHave(
                'pickupRequests',
                function ($query) {
                    $query->whereIn(
                        'status',
                        PickupStatus::active()
                    );
                }
            )
            ->lockForUpdate()
            ->get();
    }

    /**
     * Pickup statuses which can still accept shipments.
     *
     * IMPORTANT:
     *
     * Once assigned to a rider, the batch should be frozen.
     */
    private function reusablePickupStatuses(): array
    {
        return [
            PickupStatus::REQUESTED,
        ];
    }

    /**
     * Create a pickup request.
     *
     * pickup_name is REQUIRED by the database.
     */
    private function createPickupRequest(
        Merchant $merchant,
        MerchantPickupLocation $pickupLocation,
        array $data
    ): PickupRequest {

        /*
        |--------------------------------------------------------------------------
        | Resolve pickup identity
        |--------------------------------------------------------------------------
        |
        | These are intentionally resolved from the merchant pickup location.
        |
        */

        $pickupName =
            $pickupLocation->name
            ?? $pickupLocation->pickup_name
            ?? $merchant->name
            ?? 'Merchant Pickup';

        $pickupPhone =
            $pickupLocation->phone
            ?? $pickupLocation->pickup_phone
            ?? $merchant->phone
            ?? null;

        $pickupEmail =
            $pickupLocation->email
            ?? $pickupLocation->pickup_email
            ?? $merchant->email
            ?? null;

        $pickupAddress =
            $pickupLocation->address
            ?? $pickupLocation->pickup_address
            ?? null;

        $pickupCity =
            $pickupLocation->city
            ?? null;

        $pickupArea =
            $pickupLocation->area
            ?? null;

        /*
        |--------------------------------------------------------------------------
        | Build data
        |--------------------------------------------------------------------------
        */

        $pickupData = [

            'merchant_id' =>
                $merchant->id,

            'pickup_location_id' =>
                $pickupLocation->id,

            /*
            |--------------------------------------------------------------------------
            | Required pickup information
            |--------------------------------------------------------------------------
            */

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

            /*
            |--------------------------------------------------------------------------
            | Lifecycle
            |--------------------------------------------------------------------------
            */

            'status' =>
                PickupStatus::REQUESTED,

            'requested_at' =>
                now(),

            /*
            |--------------------------------------------------------------------------
            | Merchant request
            |--------------------------------------------------------------------------
            */

            'preferred_pickup_at' =>
                $data['preferred_pickup_at']
                ?? null,

            'remarks' =>
                $data['remarks']
                ?? null,

            /*
            |--------------------------------------------------------------------------
            | Initial quantity
            |--------------------------------------------------------------------------
            */

            'parcel_quantity' =>
                0,
        ];

        /*
        |--------------------------------------------------------------------------
        | Optional coordinates
        |--------------------------------------------------------------------------
        */

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

        /*
        |--------------------------------------------------------------------------
        | Schema protection
        |--------------------------------------------------------------------------
        */

        $pickupData =
            array_intersect_key(
                $pickupData,
                $columns
            );

        /*
        |--------------------------------------------------------------------------
        | Create
        |--------------------------------------------------------------------------
        */

        return PickupRequest::query()
            ->create($pickupData);
    }

    /**
     * Attach shipments to pickup.
     */
    private function attachShipments(
        PickupRequest $pickup,
        $shipments
    ): void {

        foreach ($shipments as $shipment) {

            $pickup->shipments()->firstOrCreate([
                'shipment_id' =>
                    $shipment->id,
            ]);
        }
    }

    /**
     * Safely resolve a pickup-location attribute.
     */
    private function getLocationValue(
        MerchantPickupLocation $location,
        array $attributes
    ) {

        foreach ($attributes as $attribute) {

            if (
                isset($location->{$attribute})
            ) {
                return $location->{$attribute};
            }
        }

        return null;
    }

    /**
     * Get pickup_requests columns.
     */
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
     * Get pickup request for merchant.
     */
    public function findForMerchant(
        int $merchantId,
        string $requestNumber
    ): PickupRequest {

        return PickupRequest::query()
            ->where(
                'merchant_id',
                $merchantId
            )
            ->where(
                'request_number',
                $requestNumber
            )
            ->with([
                'pickupLocation',
                'pickupBranch',
                'pickupSubBranch',
                'assignedStaff',
                'shipments.shipment',
            ])
            ->firstOrFail();
    }
}