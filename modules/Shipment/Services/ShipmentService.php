<?php

declare(strict_types=1);

namespace Modules\Shipment\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Merchant\Models\Merchant;
use Modules\Pickup\Services\ShipmentPickupAttachmentService;
use Modules\Shipment\Models\Shipment;
use App\Support\CourierStatus;

final class ShipmentService
{
    public function __construct(
        private readonly MerchantPickupLocationResolver $pickupResolver,
        private readonly BranchAssignmentService $branchAssignment,
        private readonly ShipmentNumberService $shipmentNumberService,
        private readonly ShipmentPickupAttachmentService $pickupAttachmentService,
    ) {
    }

    /**
     * Create shipment from external Store Manager.
     *
     * IMPORTANT:
     *
     * There is NO shipment creation cutoff.
     *
     * Store may create shipments:
     *
     * - before rider assignment
     * - after rider assignment
     * - while rider is travelling
     * - after rider has arrived
     *
     * BUT:
     *
     * Once the pickup is completed/closed,
     * the shipment belongs to a new pickup.
     */
    public function createFromGateway(
        int $merchantId,
        array $data
    ): Shipment {
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
                | Resolve pickup location
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

                if (
                    isset($pickupLocation->merchant_id)
                    &&
                    (int) $pickupLocation->merchant_id !==
                    (int) $merchant->id
                ) {
                    throw ValidationException::withMessages([
                        'pickup_location_id' => [
                            'Pickup location does not belong to this merchant.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Pickup coordinates
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
                | Tracking number
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
                | Self drop
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

                    /*
                    |--------------------------------------------------------------------------
                    | Pickup
                    |--------------------------------------------------------------------------
                    */

                    'pickup_location_id' =>
                        $pickupLocation->id,

                    'pickup_lat' =>
                        $pickupCoordinates['lat'],

                    'pickup_lng' =>
                        $pickupCoordinates['lng'],

                    /*
                    |--------------------------------------------------------------------------
                    | Origin
                    |--------------------------------------------------------------------------
                    */

                    'origin_branch_id' =>
                        $origin['branch_id'],

                    'origin_sub_branch_id' =>
                        $origin['sub_branch_id'],

                    /*
                    |--------------------------------------------------------------------------
                    | Receiver
                    |--------------------------------------------------------------------------
                    */

                    'receiver_name' =>
                        $data['receiver_name'],

                    'receiver_phone' =>
                        $data['receiver_phone'],

                    'receiver_email' =>
                        $data['receiver_email'] ?? null,

                    /*
                    |--------------------------------------------------------------------------
                    | Delivery
                    |--------------------------------------------------------------------------
                    */

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

                    /*
                    |--------------------------------------------------------------------------
                    | Destination
                    |--------------------------------------------------------------------------
                    */

                    'destination_branch_id' =>
                        $destination['branch_id'],

                    'destination_sub_branch_id' =>
                        $destination['sub_branch_id'],

                    /*
                    |--------------------------------------------------------------------------
                    | Service
                    |--------------------------------------------------------------------------
                    */

                    'service_type' =>
                        $data['service_type'],

                    /*
                    |--------------------------------------------------------------------------
                    | Packet
                    |--------------------------------------------------------------------------
                    */

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

                    /*
                    |--------------------------------------------------------------------------
                    | Payment
                    |--------------------------------------------------------------------------
                    */

                    'payment_type' =>
                        $data['payment_type'],

                    'delivery_charge_paid_by' =>
                        $data['delivery_charge_paid_by'],

                    /*
                    |--------------------------------------------------------------------------
                    | Pickup mode
                    |--------------------------------------------------------------------------
                    */

                    'self_drop' =>
                        $selfDrop,

                    /*
                    |--------------------------------------------------------------------------
                    | Additional
                    |--------------------------------------------------------------------------
                    */

                    'special_instructions' =>
                        $data['special_instructions'] ?? null,

                    'remarks' =>
                        $data['remarks'] ?? null,

                    /*
                    |--------------------------------------------------------------------------
                    | Initial status
                    |--------------------------------------------------------------------------
                    */

                    'status' =>
                        CourierStatus::AWAITING_PICKUP,
                ];

                /*
                |--------------------------------------------------------------------------
                | Transfer requirement
                |--------------------------------------------------------------------------
                */

                if (
                    array_key_exists(
                        'requires_transfer',
                        $this->shipmentColumns()
                    )
                ) {
                    $shipmentData['requires_transfer'] =
                        $route['requires_transfer'];
                }

                /*
                |--------------------------------------------------------------------------
                | Protect against schema differences
                |--------------------------------------------------------------------------
                */

                $shipmentData =
                    $this->filterShipmentColumns(
                        $shipmentData
                    );

                /*
                |--------------------------------------------------------------------------
                | Create shipment
                |--------------------------------------------------------------------------
                */

                $shipment =
                    Shipment::query()->create(
                        $shipmentData
                    );

                /*
                |--------------------------------------------------------------------------
                | Tracking event
                |--------------------------------------------------------------------------
                */

                $this->createTrackingEvent(
                    shipment: $shipment,
                    oldStatus: null,
                    newStatus: CourierStatus::AWAITING_PICKUP,
                    description:
                        'Shipment created successfully. Awaiting pickup.'
                );

                /*
                |--------------------------------------------------------------------------
                | AUTOMATIC PICKUP ATTACHMENT
                |--------------------------------------------------------------------------
                |
                | This is the key part of the new workflow.
                |
                | We do NOT ask the merchant to create another pickup.
                |
                | The service finds the merchant's currently open pickup
                | for this pickup location.
                |
                | If found:
                |
                |     shipment -> existing pickup
                |
                | If rider assigned:
                |
                |     rider -> notification
                |
                | If none exists:
                |
                |     create pickup -> attach shipment
                |
                */

                $this->pickupAttachmentService
                    ->attachShipmentToActivePickup(
                        shipment: $shipment,
                        merchant: $merchant,
                        pickupLocation: $pickupLocation,
                    );

                return $shipment->fresh();
            }
        );
    }

    /**
     * Build packet products.
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

    /**
     * Get shipment columns.
     */
    private function shipmentColumns(): array
    {
        return array_flip(
            DB::getSchemaBuilder()
                ->getColumnListing('shipments')
        );
    }

    /**
     * Filter shipment fields.
     */
    private function filterShipmentColumns(
        array $data
    ): array {
        return array_intersect_key(
            $data,
            $this->shipmentColumns()
        );
    }

    /**
     * Update shipment status.
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

                $shipment->merchant_status =
                    CourierStatus::merchantStatus(
                        $status
                    );

                if (
                    $status === CourierStatus::DELIVERED
                ) {
                    $shipment->delivered_at =
                        now();
                }

                if (
                    $status === CourierStatus::CANCELLED
                ) {
                    $shipment->cancelled_at =
                        now();
                }

                $shipment->save();

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