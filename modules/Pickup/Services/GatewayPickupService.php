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
use Modules\Pickup\Models\PickupRequestShipment;
use Modules\Pickup\Support\PickupStatus;
use Modules\Shipment\Models\Shipment;

final class GatewayPickupService
{
    /*
    |--------------------------------------------------------------------------
    | CREATE / REUSE PICKUP
    |--------------------------------------------------------------------------
    */

    public function create(
        int $merchantId,
        array $data
    ): PickupRequest {
        if ($merchantId <= 0) {
            throw ValidationException::withMessages([
                'merchant' => [
                    'Invalid merchant.',
                ],
            ]);
        }

        return DB::transaction(
            function () use (
                $merchantId,
                $data
            ): PickupRequest {
                $merchant = Merchant::query()
                    ->lockForUpdate()
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

                /*
                 * Validate pickup location.
                 */
                $pickupLocationId =
                    (int) ($data['pickup_location_id'] ?? 0);

                if ($pickupLocationId <= 0) {
                    throw ValidationException::withMessages([
                        'pickup_location_id' => [
                            'Pickup location is required.',
                        ],
                    ]);
                }

                $pickupLocation =
                    MerchantPickupLocation::query()
                        ->whereKey($pickupLocationId)
                        ->where(
                            'merchant_id',
                            $merchant->id
                        )
                        ->where(function ($query): void {
                            $query
                                ->whereNull('status')
                                ->orWhereIn(
                                    'status',
                                    [
                                        'active',
                                        'approved',
                                    ]
                                );
                        })
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
                 * Store reference.
                 *
                 * Example:
                 *
                 * PR-001
                 */
                $storeReference = trim(
                    (string) ($data['store_reference'] ?? '')
                );

                if ($storeReference === '') {
                    throw ValidationException::withMessages([
                        'store_reference' => [
                            'Store pickup reference is required.',
                        ],
                    ]);
                }

                /*
                 * Find existing physical pickup batch.
                 *
                 * IMPORTANT:
                 * store_reference is NOT used to identify
                 * the physical pickup.
                 */
                $openPickup = $this->findOpenPickup(
                    merchantId: $merchant->id,
                    pickupLocationId: $pickupLocation->id,
                    lock: true
                );

                /*
                 * --------------------------------------------------------------
                 * EXISTING OPEN PICKUP
                 * --------------------------------------------------------------
                 */
                if ($openPickup) {
                    $this->updatePickupRequest(
                        pickup: $openPickup,
                        data: $data
                    );

                    $shipments =
                        $this->findEligibleShipments(
                            merchantId: $merchant->id,
                            pickupLocationId: $pickupLocation->id
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

                    return $this->freshPickup(
                        $openPickup
                    );
                }

                /*
                 * --------------------------------------------------------------
                 * CREATE NEW PHYSICAL PICKUP
                 * --------------------------------------------------------------
                 */
                $pickup =
                    $this->createPickupRequest(
                        merchant: $merchant,
                        pickupLocation: $pickupLocation,
                        storeReference: $storeReference,
                        data: $data
                    );

                /*
                 * Attach every currently waiting shipment
                 * belonging to this merchant + location.
                 */
                $shipments =
                    $this->findEligibleShipments(
                        merchantId: $merchant->id,
                        pickupLocationId: $pickupLocation->id
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

                return $this->freshPickup(
                    $pickup
                );
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
         * Self-drop shipment does not require merchant pickup.
         */
        if (
            (bool) ($shipment->self_drop ?? false)
        ) {
            return null;
        }

        /*
         * Safety validation.
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
         * Shipment must still be waiting.
         */
        if (
            $shipment->status !==
            CourierStatus::AWAITING_PICKUP
        ) {
            return null;
        }

        /*
         * Lock shipment first to prevent two requests
         * attaching the same shipment simultaneously.
         */
        $shipment = Shipment::query()
            ->lockForUpdate()
            ->find($shipment->id);

        if (! $shipment) {
            return null;
        }

        if (
            $shipment->status !==
            CourierStatus::AWAITING_PICKUP
        ) {
            return null;
        }

        /*
         * Find the open physical pickup.
         */
        $pickup = $this->findOpenPickup(
            merchantId: $merchantId,
            pickupLocationId: $pickupLocationId,
            lock: true
        );

        if (! $pickup) {
            /*
             * No pickup requested yet.
             *
             * Shipment remains awaiting_pickup.
             */
            return null;
        }

        /*
         * Make sure it is not already attached to
         * another active pickup.
         */
        $alreadyAttached =
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

        if ($alreadyAttached) {
            return $pickup;
        }

        $this->attachShipmentToPickup(
            pickup: $pickup,
            shipment: $shipment
        );

        $this->recalculateParcelQuantity(
            $pickup
        );

        return $this->freshPickup(
            $pickup
        );
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
    | FIND ELIGIBLE SHIPMENTS
    |--------------------------------------------------------------------------
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

    /*
    |--------------------------------------------------------------------------
    | CREATE PICKUP
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
         * Coordinates.
         */
        $columns =
            $this->pickupRequestColumns();

        if (
            isset($columns['pickup_lat'])
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
            isset($columns['pickup_lng'])
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
         * Only write columns that really exist.
         */
        $pickupData =
            array_intersect_key(
                $pickupData,
                $columns
            );

        $pickup =
            PickupRequest::query()
                ->create($pickupData);

        /*
         * Generate Tukaatu pickup number.
         */
        if (
            $this->pickupHasColumn(
                'request_number'
            )
        ) {
            $pickup->request_number =
                sprintf(
                    'PICK-%s-%06d',
                    now()->format('Ymd'),
                    $pickup->id
                );

            $pickup->save();
        }

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
            &&
            $this->pickupHasColumn(
                'preferred_pickup_at'
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
            &&
            $this->pickupHasColumn(
                'remarks'
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
         * Ownership.
         */
        if (
            (int) $pickup->merchant_id !==
            (int) $shipment->merchant_id
        ) {
            return;
        }

        if (
            (int) $pickup->pickup_location_id !==
            (int) $shipment->pickup_location_id
        ) {
            return;
        }

        /*
         * Shipment must be pickup eligible.
         */
        if (
            $shipment->status !==
            CourierStatus::AWAITING_PICKUP
        ) {
            return;
        }

        /*
         * Already attached to this pickup.
         */
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
                ->lockForUpdate()
                ->first();

        if ($existing) {
            return;
        }

        /*
         * Cannot belong to another active pickup.
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
         * Create pivot.
         */
        $pickup->shipments()->create([
            'shipment_id' =>
                $shipment->id,
        ]);

        /*
         * If rider already has the pickup,
         * immediately promote shipment.
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

            if (
                $this->shipmentHasColumn(
                    'merchant_status'
                )
            ) {
                $shipment->merchant_status =
                    CourierStatus::merchantStatus(
                        CourierStatus::PICKUP_ASSIGNED
                    );
            }

            $shipment->save();

            $this->createTrackingEvent(
                shipment: $shipment,
                oldStatus: $oldStatus,
                newStatus: CourierStatus::PICKUP_ASSIGNED,
                description:
                    'Shipment automatically added to the active pickup request.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | RECALCULATE PARCEL QUANTITY
    |--------------------------------------------------------------------------
    */

    private function recalculateParcelQuantity(
        PickupRequest $pickup
    ): void {
        if (
            ! $this->pickupHasColumn(
                'parcel_quantity'
            )
        ) {
            return;
        }

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

        if (
            ! $schema->hasTable($table)
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
            $schema->getColumnListing(
                $table
            );

        $data =
            array_intersect_key(
                $data,
                array_flip($columns)
            );

        if ($data !== []) {
            DB::table($table)
                ->insert($data);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | FRESH PICKUP
    |--------------------------------------------------------------------------
    */

    private function freshPickup(
        PickupRequest $pickup
    ): PickupRequest {
        return $pickup->fresh([
            'merchant',
            'pickupLocation',
            'pickupBranch',
            'pickupSubBranch',
            'assignedStaff',
            'shipments.shipment',
        ]);
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
    | FIND FOR MERCHANT
    |--------------------------------------------------------------------------
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
                ->getColumnListing(
                    'pickup_requests'
                ),
            true
        );
    }

    private function shipmentHasColumn(
        string $column
    ): bool {
        return in_array(
            $column,
            DB::getSchemaBuilder()
                ->getColumnListing(
                    'shipments'
                ),
            true
        );
    }
}