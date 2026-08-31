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
     * A pickup may continue accepting shipments while it is:
     *
     * - requested
     * - assigned
     * - started
     * - arrived
     *
     * Once completed or failed, the pickup is closed and
     * a future request will create a new pickup.
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
                | Find shipments awaiting pickup
                |--------------------------------------------------------------------------
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
                | A pickup can continue receiving shipments while it is:
                |
                | requested
                | assigned
                | started
                | arrived
                |
                */

                $pickup = PickupRequest::query()
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
                | Create or update pickup
                |--------------------------------------------------------------------------
                */

                if (! $pickup) {
                    $pickup = $this->createPickupRequest(
                        merchant: $merchant,
                        pickupLocation: $pickupLocation,
                        data: $data,
                    );
                } else {
                    $this->updatePickupRequest(
                        pickup: $pickup,
                        data: $data,
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Attach eligible shipments
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
                | Return only the relationships required by GatewayPickupResource
                |--------------------------------------------------------------------------
                */

                return $pickup->fresh([
                    'shipments.shipment',
                ]);
            }
        );
    }

    /**
     * Find shipments which can be attached to this pickup.
     *
     * Eligible shipment:
     *
     * - belongs to merchant
     * - belongs to pickup location
     * - is awaiting pickup
     * - is not already attached to an active pickup
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
        | IMPORTANT BRANCH MAPPING
        |--------------------------------------------------------------------------
        |
        | The pickup location already knows which branch is responsible
        | for that physical merchant pickup location.
        |
        | merchant_pickup_locations.branch_id
        |              ↓
        | pickup_requests.pickup_branch_id
        |
        | merchant_pickup_locations.sub_branch_id
        |              ↓
        | pickup_requests.pickup_sub_branch_id
        |
        */

        $pickupData = [

            'merchant_id' =>
                $merchant->id,

            'pickup_location_id' =>
                $pickupLocation->id,

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
        | Only insert columns that actually exist
        |--------------------------------------------------------------------------
        */

        $pickupData = array_intersect_key(
            $pickupData,
            $columns
        );

        return PickupRequest::query()
            ->create($pickupData);
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
            ! empty(
                $data['remarks']
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
     * Attach a shipment to a pickup.
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
        | Create pickup/shipment pivot
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
        | If rider has already been assigned to the pickup,
        | a newly added shipment must immediately become
        | pickup_assigned.
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
     * Read a location attribute using multiple possible column names.
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
     * Get pickup_requests table columns.
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
     * Find a merchant pickup by public request number.
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
                'shipments.shipment',
            ])
            ->firstOrFail();
    }
}