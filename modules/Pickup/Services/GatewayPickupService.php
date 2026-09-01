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
    /*
    |--------------------------------------------------------------------------
    | CREATE PICKUP
    |--------------------------------------------------------------------------
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

                $pickupLocation =
                    MerchantPickupLocation::query()
                        ->whereKey(
                            $data['pickup_location_id']
                        )
                        ->where(
                            'merchant_id',
                            $merchant->id
                        )
                        ->where(
                            function ($query): void {
                                $query
                                    ->whereNull('status')
                                    ->orWhereIn(
                                        'status',
                                        [
                                            'active',
                                            'approved',
                                        ]
                                    );
                            }
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
                    (string) (
                        $data['store_reference']
                        ?? ''
                    )
                );

                if ($storeReference === '') {
                    throw ValidationException::withMessages([
                        'store_reference' => [
                            'Store pickup reference is required.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Find existing open pickup
                |--------------------------------------------------------------------------
                */

                $openPickup =
                    $this->findOpenPickup(
                        merchantId:
                            $merchant->id,

                        pickupLocationId:
                            (int) $pickupLocation->id,

                        lock:
                            true,
                    );

                /*
                |--------------------------------------------------------------------------
                | REUSE
                |--------------------------------------------------------------------------
                */

                if ($openPickup) {
                    $this->updatePickupRequest(
                        pickup: $openPickup,
                        data: $data
                    );

                    $shipments =
                        $this->findEligibleShipments(
                            merchantId:
                                $merchant->id,

                            pickupLocationId:
                                (int) $pickupLocation->id
                        );

                    foreach ($shipments as $shipment) {
                        $this->attachShipmentToPickup(
                            pickup: $openPickup,
                            shipment: $shipment
                        );
                    }

                    $this->recalculateParcelQuantity(
                        $openPickup
                    );

                    return $openPickup->fresh([
                        'merchant',
                        'pickupLocation',
                        'pickupBranch',
                        'pickupSubBranch',
                        'shipments.shipment',
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | CREATE NEW PICKUP
                |--------------------------------------------------------------------------
                */

                $pickup =
                    $this->createPickupRequest(
                        merchant:
                            $merchant,

                        pickupLocation:
                            $pickupLocation,

                        storeReference:
                            $storeReference,

                        data:
                            $data
                    );

                /*
                |--------------------------------------------------------------------------
                | Attach waiting shipments
                |--------------------------------------------------------------------------
                */

                $shipments =
                    $this->findEligibleShipments(
                        merchantId:
                            $merchant->id,

                        pickupLocationId:
                            (int) $pickupLocation->id
                    );

                foreach ($shipments as $shipment) {
                    $this->attachShipmentToPickup(
                        pickup: $pickup,
                        shipment: $shipment
                    );
                }

                $this->recalculateParcelQuantity(
                    $pickup
                );

                return $pickup->fresh([
                    'merchant',
                    'pickupLocation',
                    'pickupBranch',
                    'pickupSubBranch',
                    'shipments.shipment',
                ]);
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | AUTO ATTACH NEW SHIPMENT
    |--------------------------------------------------------------------------
    */

    public function attachShipmentToOpenPickup(
        int $merchantId,
        int $pickupLocationId,
        Shipment $shipment
    ): ?PickupRequest {
        if (
            $merchantId <= 0
            ||
            $pickupLocationId <= 0
        ) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Self drop never enters pickup
        |--------------------------------------------------------------------------
        */

        if (
            (bool) ($shipment->self_drop ?? false)
        ) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Find pickup
        |--------------------------------------------------------------------------
        */

        $pickup =
            $this->findOpenPickup(
                merchantId:
                    $merchantId,

                pickupLocationId:
                    $pickupLocationId,

                lock:
                    true
            );

        if (! $pickup) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Ownership protection
        |--------------------------------------------------------------------------
        */

        if (
            (int) $shipment->merchant_id !==
            $merchantId
        ) {
            return null;
        }

        if (
            (int) $shipment->pickup_location_id !==
            $pickupLocationId
        ) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Shipment must be awaiting pickup
        |--------------------------------------------------------------------------
        */

        if (
            $shipment->status !==
            CourierStatus::AWAITING_PICKUP
        ) {
            return $pickup;
        }

        /*
        |--------------------------------------------------------------------------
        | Attach
        |--------------------------------------------------------------------------
        */

        $this->attachShipmentToPickup(
            pickup:
                $pickup,

            shipment:
                $shipment
        );

        $this->recalculateParcelQuantity(
            $pickup
        );

        return $pickup->fresh([
            'shipments.shipment',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | FIND OPEN PICKUP
    |--------------------------------------------------------------------------
    */

    public function findOpenPickup(
        int $merchantId,
        int $pickupLocationId,
        bool $lock = false
    ): ?PickupRequest {
        if (
            $merchantId <= 0
            ||
            $pickupLocationId <= 0
        ) {
            return null;
        }

        $query = PickupRequest::query()
            ->where(
                'merchant_id',
                $merchantId
            )
            ->where(
                'pickup_location_id',
                $pickupLocationId
            )
            ->whereIn(
                'status',
                PickupStatus::acceptingShipments()
            )
            ->latest('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /*
    |--------------------------------------------------------------------------
    | ELIGIBLE SHIPMENTS
    |--------------------------------------------------------------------------
    */

    private function findEligibleShipments(
        int $merchantId,
        int $pickupLocationId
    ): Collection {
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

    /*
    |--------------------------------------------------------------------------
    | CREATE PICKUP REQUEST
    |--------------------------------------------------------------------------
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

        $pickupData = [
            'merchant_id' =>
                $merchant->id,

            'pickup_location_id' =>
                $pickupLocation->id,

            'store_reference' =>
                $storeReference,

            'pickup_branch_id' =>
                $pickupLocation->branch_id,

            'pickup_sub_branch_id' =>
                $pickupLocation->sub_branch_id,

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

        /*
        |--------------------------------------------------------------------------
        | Coordinates
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

        $pickup =
            PickupRequest::query()
                ->create($pickupData);

        /*
        |--------------------------------------------------------------------------
        | Tukaatu pickup number
        |--------------------------------------------------------------------------
        */

        $pickup->request_number =
            sprintf(
                'PICK-%s-%06d',
                now()->format('Ymd'),
                $pickup->id
            );

        $pickup->save();

        return $pickup;
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE PICKUP
    |--------------------------------------------------------------------------
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

    /*
    |--------------------------------------------------------------------------
    | ATTACH SHIPMENT
    |--------------------------------------------------------------------------
    */

    private function attachShipmentToPickup(
        PickupRequest $pickup,
        Shipment $shipment
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Merchant protection
        |--------------------------------------------------------------------------
        */

        if (
            (int) $pickup->merchant_id !==
            (int) $shipment->merchant_id
        ) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Location protection
        |--------------------------------------------------------------------------
        */

        if (
            (int) $pickup->pickup_location_id !==
            (int) $shipment->pickup_location_id
        ) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Already attached
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
        | Another active pickup
        |--------------------------------------------------------------------------
        */

        $alreadyActive =
            $shipment->pickupRequests()
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
            if (
                $shipment->status !==
                CourierStatus::PICKUP_ASSIGNED
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
                        'Shipment automatically added to the active pickup request.'
                );
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | PARCEL QUANTITY
    |--------------------------------------------------------------------------
    */

    private function recalculateParcelQuantity(
        PickupRequest $pickup
    ): void {
        $pickup->parcel_quantity =
            $pickup
                ->activeShipments()
                ->count();

        $pickup->save();
    }

    /*
    |--------------------------------------------------------------------------
    | TRACKING EVENT
    |--------------------------------------------------------------------------
    */

    private function createTrackingEvent(
        Shipment $shipment,
        ?string $oldStatus,
        string $newStatus,
        string $description
    ): void {
        $table = 'tracking_events';

        $schema = DB::getSchemaBuilder();

        if (! $schema->hasTable($table)) {
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
            $schema->getColumnListing($table);

        $data =
            array_intersect_key(
                $data,
                array_flip($columns)
            );

        DB::table($table)
            ->insert($data);
    }

    /*
    |--------------------------------------------------------------------------
    | LOCATION VALUE
    |--------------------------------------------------------------------------
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

    /*
    |--------------------------------------------------------------------------
    | PICKUP REQUEST COLUMNS
    |--------------------------------------------------------------------------
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

    /*
    |--------------------------------------------------------------------------
    | FIND MERCHANT PICKUP
    |--------------------------------------------------------------------------
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
                'merchant',
                'pickupLocation',
                'pickupBranch',
                'pickupSubBranch',
                'shipments.shipment',
            ])
            ->firstOrFail();
    }
}