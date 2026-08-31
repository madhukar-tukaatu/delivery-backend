<?php

declare(strict_types=1);

namespace Modules\Pickup\Services;

use App\Support\CourierStatus;
use Illuminate\Support\Collection;
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
     * Store sends:
     *
     * - pickup_location_id
     * - store_reference
     *
     * Tukaatu generates:
     *
     * - request_number
     *
     * Example:
     *
     * Store 1:
     *   PR-001
     *
     * Tukaatu:
     *   PICK-20260831-000081
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
                        'merchant_pickup_locations.id',
                        $data['pickup_location_id']
                    )
                    ->where(
                        'merchant_pickup_locations.merchant_id',
                        $merchant->id
                    )
                    ->where(
                        'merchant_pickup_locations.status',
                        'active'
                    )
                    ->lockForUpdate()
                    ->first();

                if (! $pickupLocation) {
                    throw ValidationException::withMessages([
                        'pickup_location_id' => [
                            'Pickup location does not belong to this merchant or is inactive.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Store reference
                |--------------------------------------------------------------------------
                */

                $storeReference = trim(
                    (string) $data['store_reference']
                );

                if ($storeReference === '') {
                    throw ValidationException::withMessages([
                        'store_reference' => [
                            'Store reference is required.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Check whether this exact store/location PR already exists.
                |--------------------------------------------------------------------------
                |
                | Example:
                |
                | Location 15 -> PR-001
                | Location 15 -> PR-001
                |
                | This should NOT accidentally create a second pickup.
                |
                */

                $existingPickup = PickupRequest::query()
                    ->where(
                        'merchant_id',
                        $merchant->id
                    )
                    ->where(
                        'pickup_location_id',
                        $pickupLocation->id
                    )
                    ->where(
                        'store_reference',
                        $storeReference
                    )
                    ->whereIn(
                        'status',
                        PickupStatus::acceptingShipments()
                    )
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                /*
                |--------------------------------------------------------------------------
                | Find shipments awaiting pickup
                |--------------------------------------------------------------------------
                */

                $shipments = $this->findEligibleShipments(
                    merchantId: $merchant->id,
                    pickupLocationId: $pickupLocation->id,
                );

                if (
                    $shipments->isEmpty()
                    && ! $existingPickup
                ) {
                    throw ValidationException::withMessages([
                        'pickup' => [
                            'There are no shipments awaiting pickup for this pickup location.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Create or reuse pickup
                |--------------------------------------------------------------------------
                */

                if (! $existingPickup) {
                    $pickup = $this->createPickupRequest(
                        merchant: $merchant,
                        pickupLocation: $pickupLocation,
                        storeReference: $storeReference,
                        data: $data,
                    );
                } else {
                    $pickup = $existingPickup;

                    $this->updatePickupRequest(
                        pickup: $pickup,
                        data: $data,
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Attach shipments
                |--------------------------------------------------------------------------
                */

                foreach ($shipments as $shipment) {
                    $this->attachShipmentToPickup(
                        pickup: $pickup,
                        shipment: $shipment,
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Recalculate parcel quantity
                |--------------------------------------------------------------------------
                */

                $pickup->parcel_quantity = $pickup
                    ->activeShipments()
                    ->count();

                $pickup->save();

                /*
                |--------------------------------------------------------------------------
                | Return complete pickup
                |--------------------------------------------------------------------------
                */

                return $pickup->fresh([
                    'pickupLocation',
                    'pickupBranch',
                    'pickupSubBranch',
                    'shipments.shipment',
                ]);
            }
        );
    }

    /**
     * Find shipments which can be attached to this pickup.
     */
    private function findEligibleShipments(
        int $merchantId,
        int $pickupLocationId
    ): Collection {
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
                function ($query): void {
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
     * Create a new pickup request.
     */
    private function createPickupRequest(
        Merchant $merchant,
        MerchantPickupLocation $pickupLocation,
        string $storeReference,
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

        /*
        |--------------------------------------------------------------------------
        | Branch mapping
        |--------------------------------------------------------------------------
        |
        | Merchant pickup location determines responsible branch.
        |
        | pickup_location.branch_id
        |          ↓
        | pickup_request.pickup_branch_id
        |
        | pickup_location.sub_branch_id
        |          ↓
        | pickup_request.pickup_sub_branch_id
        |
        */

        $pickupData = [

            /*
             * Merchant / store identity.
             */
            'merchant_id' =>
                $merchant->id,

            'pickup_location_id' =>
                $pickupLocation->id,

            'store_reference' =>
                $storeReference,

            /*
             * Branch ownership.
             */
            'pickup_branch_id' =>
                $pickupLocation->branch_id,

            'pickup_sub_branch_id' =>
                $pickupLocation->sub_branch_id,

            /*
             * Pickup contact information.
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
             * Status.
             */
            'status' =>
                PickupStatus::REQUESTED,

            'requested_at' =>
                now(),

            /*
             * Store request information.
             */
            'preferred_pickup_at' =>
                $data['preferred_pickup_at']
                ??
                null,

            'remarks' =>
                $data['remarks']
                ??
                null,

            /*
             * Will be recalculated after shipments
             * are attached.
             */
            'parcel_quantity' =>
                0,
        ];

        /*
        |--------------------------------------------------------------------------
        | Coordinates
        |--------------------------------------------------------------------------
        */

        $columns = $this->pickupRequestColumns();

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
        | Only use existing columns
        |--------------------------------------------------------------------------
        */

        $pickupData = array_intersect_key(
            $pickupData,
            $columns
        );

        /*
        |--------------------------------------------------------------------------
        | Create pickup
        |--------------------------------------------------------------------------
        |
        | request_number is intentionally generated AFTER insert
        | because we can safely use the database ID.
        |
        */

        $pickup = PickupRequest::query()
            ->create($pickupData);

        /*
        |--------------------------------------------------------------------------
        | Generate Tukaatu pickup number
        |--------------------------------------------------------------------------
        |
        | Example:
        |
        | PICK-20260831-000081
        |
        | This is globally unique because it contains the
        | pickup_requests.id.
        |
        */

        $pickup->request_number = sprintf(
            'PICK-%s-%06d',
            now()->format('Ymd'),
            $pickup->id
        );

        $pickup->save();

        return $pickup;
    }

    /**
     * Update an existing reusable pickup.
     */
    private function updatePickupRequest(
        PickupRequest $pickup,
        array $data
    ): void {
        $dirty = false;

        if (
            ! empty(
                $data['preferred_pickup_at']
            )
        ) {
            $pickup->preferred_pickup_at =
                $data['preferred_pickup_at'];

            $dirty = true;
        }

        if (
            array_key_exists(
                'remarks',
                $data
            )
        ) {
            $pickup->remarks =
                $data['remarks'];

            $dirty = true;
        }

        if ($dirty) {
            $pickup->save();
        }
    }

    /**
     * Attach shipment to pickup.
     */
    private function attachShipmentToPickup(
        PickupRequest $pickup,
        Shipment $shipment
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Prevent duplicate active attachment
        |--------------------------------------------------------------------------
        */

        $existing = $pickup->shipments()
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
                shipment: $shipment,
                oldStatus: $oldStatus,
                newStatus: CourierStatus::PICKUP_ASSIGNED,
                description:
                    'Shipment added to an active pickup request. Rider is already assigned.',
            );
        }
    }

    /**
     * Create shipment tracking event.
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

        $columns = DB::getSchemaBuilder()
            ->getColumnListing($table);

        $data = array_intersect_key(
            $data,
            array_flip($columns)
        );

        DB::table($table)
            ->insert($data);
    }

    /**
     * Read location attribute using multiple possible names.
     */
    private function getLocationValue(
        MerchantPickupLocation $location,
        array $attributes
    ): mixed {
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
     * Find pickup by Tukaatu-generated request number.
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
                'merchant',
                'pickupLocation',
                'pickupBranch',
                'pickupSubBranch',
                'shipments.shipment',
            ])
            ->firstOrFail();
    }
}