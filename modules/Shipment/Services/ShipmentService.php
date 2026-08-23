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

    /**
     * Create shipment from external Store Manager.
     *
     * One merchant order
     *      ↓
     * One shipment / packet
     *      ↓
     * One or more products
     */
    public function createFromGateway(
        int $merchantId,
        array $data
    ): Shipment {
        $merchant = Merchant::query()
            ->find($merchantId);

        if (! $merchant) {
            throw ValidationException::withMessages([
                'merchant' =>
                    'Authenticated merchant was not found.',
            ]);
        }

        if ($merchant->status !== 'active') {
            throw ValidationException::withMessages([
                'merchant' =>
                    'Merchant account is not active.',
            ]);
        }

        $packet = $data['packet'] ?? null;

        if (! is_array($packet)) {
            throw ValidationException::withMessages([
                'packet' =>
                    'Packet details are required.',
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
                        'pickup_location_id' =>
                            'Pickup location could not be resolved.',
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Make sure pickup belongs to merchant
                |--------------------------------------------------------------------------
                */

                if (
                    isset($pickupLocation->merchant_id)
                    &&
                    (int) $pickupLocation->merchant_id
                    !== $merchant->id
                ) {
                    throw ValidationException::withMessages([
                        'pickup_location_id' =>
                            'Pickup location does not belong to this merchant.',
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Pickup coordinates
                |--------------------------------------------------------------------------
                */

                $pickupCoordinates =
                    $this->branchAssignment
                        ->pickupCoordinates(
                            $pickupLocation,
                            $merchant
                        );

                /*
                |--------------------------------------------------------------------------
                | Origin
                |--------------------------------------------------------------------------
                */

                $origin =
                    $this->branchAssignment
                        ->resolveOrigin(
                            $merchant,
                            $pickupLocation
                        );

                if (! $origin['branch_id']) {
                    throw ValidationException::withMessages([
                        'pickup_location_id' =>
                            'Unable to determine origin branch.',
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Destination
                |--------------------------------------------------------------------------
                */

                $destination =
                    $this->branchAssignment
                        ->resolveDestination([
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
                        'delivery_lat' =>
                            'Unable to determine destination branch.',
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Route
                |--------------------------------------------------------------------------
                */

                $route =
                    $this->branchAssignment
                        ->buildRoute(
                            $origin,
                            $destination
                        );

                /*
                |--------------------------------------------------------------------------
                | Generate packet tracking number
                |--------------------------------------------------------------------------
                */

                $trackingNumber =
                    $this->shipmentNumberService
                        ->generate();

                /*
                |--------------------------------------------------------------------------
                | Shipment data
                |--------------------------------------------------------------------------
                */

                $shipmentData = [

                    /*
                    |------------------------------------------------------------------
                    | Tracking
                    |------------------------------------------------------------------
                    */

                    'tracking_number' =>
                        $trackingNumber,

                    /*
                    |------------------------------------------------------------------
                    | Merchant
                    |------------------------------------------------------------------
                    */

                    'merchant_id' =>
                        $merchant->id,

                    'merchant_order_id' =>
                        $data['merchant_order_id'],

                    'order_source' =>
                        'store_manager',

                    /*
                    |------------------------------------------------------------------
                    | Pickup
                    |------------------------------------------------------------------
                    */

                    'pickup_location_id' =>
                        $pickupLocation->id,

                    'pickup_lat' =>
                        $pickupCoordinates['lat'],

                    'pickup_lng' =>
                        $pickupCoordinates['lng'],

                    /*
                    |------------------------------------------------------------------
                    | Origin
                    |------------------------------------------------------------------
                    */

                    'origin_branch_id' =>
                        $origin['branch_id'],

                    'origin_sub_branch_id' =>
                        $origin['sub_branch_id'],

                    /*
                    |------------------------------------------------------------------
                    | Receiver
                    |------------------------------------------------------------------
                    */

                    'receiver_name' =>
                        $data['receiver_name'],

                    'receiver_phone' =>
                        $data['receiver_phone'],

                    'receiver_email' =>
                        $data['receiver_email'] ?? null,

                    /*
                    |------------------------------------------------------------------
                    | Delivery
                    |------------------------------------------------------------------
                    */

                    'delivery_address' =>
                        $data['delivery_address'],

                    'delivery_city' =>
                        $data['delivery_city'] ?? null,

                    'delivery_area' =>
                        $data['delivery_area'] ?? null,

                    'delivery_lat' =>
                        $data['delivery_lat'],

                    'delivery_lng' =>
                        $data['delivery_lng'],

                    /*
                    |------------------------------------------------------------------
                    | Destination
                    |------------------------------------------------------------------
                    */

                    'destination_branch_id' =>
                        $destination['branch_id'],

                    'destination_sub_branch_id' =>
                        $destination['sub_branch_id'],

                    /*
                    |------------------------------------------------------------------
                    | Service
                    |------------------------------------------------------------------
                    */

                    'service_type' =>
                        $data['service_type'],

                    /*
                    |------------------------------------------------------------------
                    | Packet
                    |------------------------------------------------------------------
                    */

                    'parcel_type' =>
                        $packet['parcel_type'],

                    'product_description' =>
                        $packet['description'] ?? null,

                    'quantity' =>
                        $packet['quantity'],

                    'weight' =>
                        $packet['weight'],

                    'declared_value' =>
                        $packet['declared_value'],

                    'fragile' =>
                        $packet['fragile'],

                    /*
                    |------------------------------------------------------------------
                    | Payment
                    |------------------------------------------------------------------
                    */

                    'payment_type' =>
                        $data['payment_type'],

                    'delivery_charge_paid_by' =>
                        $data['delivery_charge_paid_by'],

                    /*
                    |------------------------------------------------------------------
                    | Pickup mode
                    |------------------------------------------------------------------
                    */

                    'self_drop' =>
                        $data['self_drop'] ?? null,

                    /*
                    |------------------------------------------------------------------
                    | Additional
                    |------------------------------------------------------------------
                    */

                    'special_instructions' =>
                        $data['special_instructions'] ?? null,

                    'remarks' =>
                        $data['remarks'] ?? null,

                    /*
                    |------------------------------------------------------------------
                    | Status
                    |------------------------------------------------------------------
                    */

                    'status' =>
                        CourierStatus::AWAITING_PICKUP,
                ];

                /*
                |--------------------------------------------------------------------------
                | Optional route information
                |--------------------------------------------------------------------------
                |
                | Only store if these columns actually exist.
                |
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
                | Filter shipment columns
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
                | Create product records
                |--------------------------------------------------------------------------
                */

                $this->createShipmentProducts(
                    $shipment,
                    $products,
                    $trackingNumber
                );

                /*
                |--------------------------------------------------------------------------
                | Initial tracking event
                |--------------------------------------------------------------------------
                */

                $this->createTrackingEvent(
                    $shipment,
                    null,
                    CourierStatus::AWAITING_PICKUP,
                    'Shipment created successfully. Awaiting pickup.'
                );

                return $shipment->fresh();
            }
        );
    }

    /**
     * Create products under packet.
     *
     * Example:
     *
     * Packet:
     * TKT-20260823-852101
     *
     * Product 1:
     * TKT-20260823-852101-01
     *
     * Product 2:
     * TKT-20260823-852101-02
     */
    private function createShipmentProducts(
        Shipment $shipment,
        array $products,
        string $packetTrackingNumber
    ): void {
        if (
            ! DB::getSchemaBuilder()
                ->hasTable('shipment_products')
        ) {
            return;
        }

        if ($products === []) {
            return;
        }

        $table = 'shipment_products';

        $columns =
            DB::getSchemaBuilder()
                ->getColumnListing($table);

        foreach ($products as $index => $product) {

            $productIndex =
                $index + 1;

            $productTrackingNumber =
                $packetTrackingNumber
                . '-'
                . str_pad(
                    (string) $productIndex,
                    2,
                    '0',
                    STR_PAD_LEFT
                );

            $productData = [

                'shipment_id' =>
                    $shipment->id,

                'tracking_number' =>
                    $productTrackingNumber,

                'product_id' =>
                    $product['product_id'] ?? null,

                'product_name' =>
                    $product['name'] ?? null,

                'quantity' =>
                    $product['quantity'] ?? 1,

                'unit_price' =>
                    $product['unit_price'] ?? null,

                'unit_weight' =>
                    $product['unit_weight'] ?? null,

                'parcel_type' =>
                    $product['parcel_type'] ?? null,

                'created_at' =>
                    now(),

                'updated_at' =>
                    now(),
            ];

            $productData =
                array_intersect_key(
                    $productData,
                    array_flip($columns)
                );

            DB::table($table)
                ->insert($productData);
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
        $table =
            'shipment_tracking_events';

        if (
            ! DB::getSchemaBuilder()
                ->hasTable($table)
        ) {
            return;
        }

        $data = [
            'shipment_id' =>
                $shipment->id,

            'old_status' =>
                $oldStatus,

            'status' =>
                $newStatus,

            'description' =>
                $description,

            'location' =>
                null,

            'meta_json' =>
                json_encode([]),

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
     * Filter shipment fields against actual schema.
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

                $shipment->save();

                $this->createTrackingEvent(
                    $shipment,
                    $oldStatus,
                    $status,
                    $note
                        ?? 'Shipment status updated.'
                );

                return $shipment->fresh();
            }
        );
    }
}