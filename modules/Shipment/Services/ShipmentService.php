<?php

declare(strict_types=1);

namespace Modules\Shipment\Services;

use App\Support\CourierStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Merchant\Models\Merchant;
use Modules\Shipment\Models\Shipment;

final class ShipmentService
{
    public function __construct(
        private readonly MerchantPickupLocationResolver $pickupResolver,
        private readonly BranchAssignmentService $branchAssignment,
        private readonly ShipmentNumberService $shipmentNumberService,
    ) {
    }

    public function createFromGateway(
        int $merchantId,
        array $data
    ): Shipment {
        $merchant = Merchant::query()->find($merchantId);

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

        $packet = $data['packet'] ?? null;

        if (! is_array($packet)) {
            throw ValidationException::withMessages([
                'packet' => [
                    'Packet details are required.',
                ],
            ]);
        }

        $products = $packet['products'] ?? [];

        if (! is_array($products)) {
            $products = [];
        }

        return DB::transaction(
            function () use (
                $merchant,
                $data,
                $packet,
                $products
            ): Shipment {
                /*
                |--------------------------------------------------------------------------
                | Idempotency
                |--------------------------------------------------------------------------
                */

                $existing = Shipment::query()
                    ->where(
                        'merchant_id',
                        $merchant->id
                    )
                    ->where(
                        'merchant_order_id',
                        $data['merchant_order_id']
                    )
                    ->first();

                if ($existing) {
                    return $existing;
                }

                /*
                |--------------------------------------------------------------------------
                | Pickup Location
                |--------------------------------------------------------------------------
                */

                $pickupLocation =
                    $this->pickupResolver->resolve(
                        $merchant,
                        $data
                    );

                if (! $pickupLocation) {
                    throw ValidationException::withMessages([
                        'pickup_location_id' => [
                            'Pickup location could not be resolved.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Ownership
                |--------------------------------------------------------------------------
                */

                if (
                    isset($pickupLocation->merchant_id)
                    &&
                    (int) $pickupLocation->merchant_id
                    !== (int) $merchant->id
                ) {
                    throw ValidationException::withMessages([
                        'pickup_location_id' => [
                            'Pickup location does not belong to this merchant.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Pickup Coordinates
                |--------------------------------------------------------------------------
                */

                $pickupCoordinates =
                    $this->branchAssignment->pickupCoordinates(
                        $pickupLocation,
                        $merchant
                    );

                /*
                |--------------------------------------------------------------------------
                | Origin
                |--------------------------------------------------------------------------
                */

                $origin =
                    $this->branchAssignment->resolveOrigin(
                        $merchant,
                        $pickupLocation
                    );

                if (! $origin['branch_id']) {
                    throw ValidationException::withMessages([
                        'pickup_location_id' => [
                            'Unable to determine origin branch.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Destination
                |--------------------------------------------------------------------------
                */

                $destination =
                    $this->branchAssignment->resolveDestination([
                        'latitude' =>
                            $data['delivery_lat'],

                        'longitude' =>
                            $data['delivery_lng'],

                        'city' =>
                            $data['delivery_city'] ?? null,

                        'area' =>
                            $data['delivery_area'] ?? null,
                    ]);

                if (! $destination['branch_id']) {
                    throw ValidationException::withMessages([
                        'delivery_lat' => [
                            'Unable to determine destination branch.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Route
                |--------------------------------------------------------------------------
                */

                $route =
                    $this->branchAssignment->buildRoute(
                        $origin,
                        $destination
                    );

                /*
                |--------------------------------------------------------------------------
                | Tracking Number
                |--------------------------------------------------------------------------
                */

                $trackingNumber =
                    $this->shipmentNumberService->generate();

                /*
                |--------------------------------------------------------------------------
                | Products
                |--------------------------------------------------------------------------
                */

                $packetProducts =
                    $this->buildPacketProducts(
                        $products,
                        $trackingNumber
                    );

                /*
                |--------------------------------------------------------------------------
                | Self Drop
                |--------------------------------------------------------------------------
                */

                $selfDrop = filter_var(
                    $data['self_drop'] ?? false,
                    FILTER_VALIDATE_BOOLEAN
                );

                /*
                |--------------------------------------------------------------------------
                | Shipment
                |--------------------------------------------------------------------------
                */

                $shipmentData = [
                    'tracking_number' =>
                        $trackingNumber,

                    'merchant_id' =>
                        $merchant->id,

                    'merchant_order_id' =>
                        $data['merchant_order_id'],

                    'order_source' =>
                        'store_manager',

                    'pickup_location_id' =>
                        $pickupLocation->id,

                    'pickup_lat' =>
                        $pickupCoordinates['lat'],

                    'pickup_lng' =>
                        $pickupCoordinates['lng'],

                    'origin_branch_id' =>
                        $origin['branch_id'],

                    'origin_sub_branch_id' =>
                        $origin['sub_branch_id'] ?? null,

                    'receiver_name' =>
                        $data['receiver_name'],

                    'receiver_phone' =>
                        $data['receiver_phone'],

                    'receiver_email' =>
                        $data['receiver_email'] ?? null,

                    'delivery_address' =>
                        $data['delivery_address'] ?? null,

                    'delivery_city' =>
                        $data['delivery_city'] ?? null,

                    'delivery_area' =>
                        $data['delivery_area'] ?? null,

                    'delivery_lat' =>
                        $data['delivery_lat'],

                    'delivery_lng' =>
                        $data['delivery_lng'],

                    'destination_branch_id' =>
                        $destination['branch_id'],

                    'destination_sub_branch_id' =>
                        $destination['sub_branch_id'] ?? null,

                    'route_id' =>
                        $route['route_id'] ?? null,

                    'service_type' =>
                        $data['service_type'],

                    'parcel_type' =>
                        $packet['parcel_type'],

                    'description' =>
                        $packet['description'] ?? null,

                    'quantity' =>
                        $packet['quantity'],

                    'weight' =>
                        $packet['weight'],

                    'declared_value' =>
                        $packet['declared_value'],

                    'fragile' =>
                        $packet['fragile'],

                    'packet_products' =>
                        $packetProducts,

                    'payment_type' =>
                        $data['payment_type'],

                    'delivery_charge_paid_by' =>
                        $data['delivery_charge_paid_by'],

                    'self_drop' =>
                        $selfDrop,

                    'special_instructions' =>
                        $data['special_instructions'] ?? null,

                    'remarks' =>
                        $data['remarks'] ?? null,

                    'status' =>
                        CourierStatus::AWAITING_PICKUP,
                ];

                /*
                |--------------------------------------------------------------------------
                | Optional Schema Columns
                |--------------------------------------------------------------------------
                */

                $columns = $this->shipmentColumns();

                if (array_key_exists(
                    'requires_transfer',
                    $columns
                )) {
                    $shipmentData['requires_transfer'] =
                        (bool) (
                            $route['requires_transfer']
                            ?? false
                        );
                }

                if (array_key_exists(
                    'route_distance_km',
                    $columns
                )) {
                    $shipmentData['route_distance_km'] =
                        $route['distance_km'] ?? 0;
                }

                /*
                |--------------------------------------------------------------------------
                | Schema Protection
                |--------------------------------------------------------------------------
                */

                $shipmentData =
                    $this->filterShipmentColumns(
                        $shipmentData
                    );

                /*
                |--------------------------------------------------------------------------
                | Create
                |--------------------------------------------------------------------------
                */

                $shipment =
                    Shipment::query()->create(
                        $shipmentData
                    );

                /*
                |--------------------------------------------------------------------------
                | Tracking Event
                |--------------------------------------------------------------------------
                */

                $this->createTrackingEvent(
                    shipment: $shipment,
                    oldStatus: null,
                    newStatus: CourierStatus::AWAITING_PICKUP,
                    description:
                        'Shipment created successfully. Awaiting pickup request.'
                );

                /*
                |--------------------------------------------------------------------------
                | IMPORTANT
                |--------------------------------------------------------------------------
                |
                | Shipment creation does NOT create a pickup request.
                |
                */

                return $shipment->fresh();
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | MANUAL SHIPMENT CREATION
    |--------------------------------------------------------------------------
    |
    | Your ShipmentController calls:
    |
    | $service->create(...)
    |
    | Therefore this method MUST exist.
    |
    */

    public function create(
        array $data,
        ?int $userId = null,
        ?int $merchantId = null,
        string $source = 'manual'
    ): Shipment {
        /*
        |--------------------------------------------------------------------------
        | Merchant
        |--------------------------------------------------------------------------
        */

        if ($merchantId !== null) {
            $data['merchant_id'] = $merchantId;
        }

        /*
        |--------------------------------------------------------------------------
        | If the manual shipment already contains a merchant,
        | use the same gateway creation pipeline where possible.
        |--------------------------------------------------------------------------
        */

        if (
            ! empty($data['merchant_id'])
            && isset($data['pickup_location_id'])
            && isset($data['delivery_lat'])
            && isset($data['delivery_lng'])
            && isset($data['packet'])
        ) {
            return $this->createFromGateway(
                (int) $data['merchant_id'],
                $data
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Generic manual creation
        |--------------------------------------------------------------------------
        */

        return DB::transaction(
            function () use (
                $data,
                $userId,
                $source
            ): Shipment {
                $trackingNumber =
                    $data['tracking_number']
                    ?? $this->shipmentNumberService->generate();

                $shipmentData = array_merge(
                    $data,
                    [
                        'tracking_number' =>
                            $trackingNumber,

                        'order_source' =>
                            $source,

                        'status' =>
                            $data['status']
                            ?? CourierStatus::AWAITING_PICKUP,
                    ]
                );

                unset(
                    $shipmentData['packet']
                );

                $shipmentData =
                    $this->filterShipmentColumns(
                        $shipmentData
                    );

                $shipment =
                    Shipment::query()->create(
                        $shipmentData
                    );

                $this->createTrackingEvent(
                    shipment: $shipment,
                    oldStatus: null,
                    newStatus: $shipment->status,
                    description:
                        'Shipment created.'
                );

                return $shipment->fresh();
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PACKET PRODUCTS
    |--------------------------------------------------------------------------
    */

    private function buildPacketProducts(
        array $products,
        string $packetTrackingNumber
    ): array {
        if ($products === []) {
            return [];
        }

        $packetProducts = [];

        foreach (
            array_values($products)
            as $index => $product
        ) {
            $productNumber = $index + 1;

            $productTrackingNumber =
                sprintf(
                    '%s-%02d',
                    $packetTrackingNumber,
                    $productNumber
                );

            $packetProducts[] = [
                'product_tracking_number' =>
                    $productTrackingNumber,

                'product_id' =>
                    isset($product['product_id'])
                        ? (string) $product['product_id']
                        : null,

                'name' =>
                    isset($product['name'])
                        ? (string) $product['name']
                        : null,

                'quantity' =>
                    (int) (
                        $product['quantity'] ?? 1
                    ),

                'unit_price' =>
                    isset($product['unit_price'])
                        ? (float) $product['unit_price']
                        : null,

                'unit_weight' =>
                    isset($product['unit_weight'])
                        ? (float) $product['unit_weight']
                        : null,

                'parcel_type' =>
                    $product['parcel_type']
                    ??
                    $product['package_type']
                    ??
                    $product['parcelType']
                    ??
                    null,
            ];
        }

        return $packetProducts;
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

            'status' =>
                $newStatus,

            'merchant_status' =>
                CourierStatus::merchantStatus(
                    $newStatus
                ),

            'branch_id' =>
                $shipment->origin_branch_id,

            'sub_branch_id' =>
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

        DB::table($table)->insert($data);
    }

    /*
    |--------------------------------------------------------------------------
    | SHIPMENT COLUMNS
    |--------------------------------------------------------------------------
    */

    private function shipmentColumns(): array
    {
        return array_flip(
            DB::getSchemaBuilder()
                ->getColumnListing('shipments')
        );
    }

    /*
    |--------------------------------------------------------------------------
    | FILTER SHIPMENT COLUMNS
    |--------------------------------------------------------------------------
    */

    private function filterShipmentColumns(
        array $data
    ): array {
        return array_intersect_key(
            $data,
            $this->shipmentColumns()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE STATUS
    |--------------------------------------------------------------------------
    */

    public function updateStatus(
        Shipment $shipment,
        string $status,
        ?int $userId = null,
        ?string $note = null
    ): Shipment {
        return DB::transaction(
            function () use (
                $shipment,
                $status,
                $userId,
                $note
            ): Shipment {
                $oldStatus =
                    $shipment->status;

                $shipment->status =
                    $status;

                /*
                |--------------------------------------------------------------------------
                | Merchant Status
                |--------------------------------------------------------------------------
                */

                $shipment->merchant_status =
                    CourierStatus::merchantStatus(
                        $status
                    );

                /*
                |--------------------------------------------------------------------------
                | Delivered
                |--------------------------------------------------------------------------
                */

                if (
                    $status === CourierStatus::DELIVERED
                ) {
                    $shipment->delivered_at =
                        now();
                }

                /*
                |--------------------------------------------------------------------------
                | Cancelled
                |--------------------------------------------------------------------------
                */

                if (
                    $status === CourierStatus::CANCELLED
                ) {
                    $shipment->cancelled_at =
                        now();
                }

                /*
                |--------------------------------------------------------------------------
                | Save
                |--------------------------------------------------------------------------
                */

                $shipment->save();

                /*
                |--------------------------------------------------------------------------
                | Tracking
                |--------------------------------------------------------------------------
                */

                $this->createTrackingEvent(
                    shipment: $shipment,
                    oldStatus: $oldStatus,
                    newStatus: $status,
                    description:
                        $note
                        ??
                        'Shipment status updated.'
                );

                return $shipment->fresh();
            }
        );
    }
}