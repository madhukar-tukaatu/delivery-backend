<?php

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

        $merchant =
            Merchant::query()
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

        $packet =
            $data['packet'] ?? null;

        if (! is_array($packet)) {
            throw ValidationException::withMessages([
                'packet' =>
                    'Packet details are required.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Products are optional
        |--------------------------------------------------------------------------
        */

        $products =
            $packet['products'] ?? [];

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

                $existing =
                    Shipment::query()
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
                | Pickup
                |--------------------------------------------------------------------------
                */

                $pickupLocation =
                    $this->pickupResolver
                        ->resolve(
                            $merchant,
                            $data
                        );

                if (! $pickupLocation) {
                    throw ValidationException::withMessages([
                        'pickup_location_id' =>
                            'Pickup location could not be resolved.',
                    ]);
                }

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
                | Tracking
                |--------------------------------------------------------------------------
                */

                $trackingNumber =
                    $this->shipmentNumberService
                        ->generate();

                /*
                |--------------------------------------------------------------------------
                | Normalize products
                |--------------------------------------------------------------------------
                */

                $packetProducts =
                    $this->normalizePacketProducts(
                        $products
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
                        $origin['sub_branch_id'] ?? null,

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
                    |--------------------------------------------------------------------------
                    | Destination
                    |--------------------------------------------------------------------------
                    */

                    'destination_branch_id' =>
                        $destination['branch_id'],

                    'destination_sub_branch_id' =>
                        $destination['sub_branch_id'] ?? null,

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

                    /*
                    |--------------------------------------------------------------------------
                    | Product details
                    |--------------------------------------------------------------------------
                    */

                    'packet_products' =>
                        $packetProducts ?: null,

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
                        (bool) (
                            $data['self_drop']
                            ?? false
                        ),

                    /*
                    |--------------------------------------------------------------------------
                    | Additional
                    |--------------------------------------------------------------------------
                    */

                    'special_instructions' =>
                        $data['special_instructions']
                        ?? null,

                    'remarks' =>
                        $data['remarks']
                        ?? null,

                    /*
                    |--------------------------------------------------------------------------
                    | Status
                    |--------------------------------------------------------------------------
                    */

                    'status' =>
                        CourierStatus::AWAITING_PICKUP,

                    'merchant_status' =>
                        CourierStatus::merchantStatus(
                            CourierStatus::AWAITING_PICKUP
                        ),
                ];

                /*
                |--------------------------------------------------------------------------
                | Route optional fields
                |--------------------------------------------------------------------------
                */

                if (
                    isset($route['requires_transfer'])
                    &&
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
                | Filter according to current DB schema
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
                    Shipment::query()
                        ->create($shipmentData);

                /*
                |--------------------------------------------------------------------------
                | Tracking event
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
     * Normalize product data before storing it as JSON.
     */
    private function normalizePacketProducts(
        array $products
    ): array {
        $normalized = [];

        foreach ($products as $product) {

            if (! is_array($product)) {
                continue;
            }

            $normalized[] = [
                'product_id' =>
                    $product['product_id'] ?? null,

                'name' =>
                    $product['name'] ?? null,

                'quantity' =>
                    (int) (
                        $product['quantity']
                        ?? 1
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
                    ?? null,
            ];
        }

        return $normalized;
    }

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

        $columns =
            DB::getSchemaBuilder()
                ->getColumnListing($table);

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
                $shipment->current_branch_id
                ?? $shipment->origin_branch_id,

            'sub_branch_id' =>
                $shipment->current_sub_branch_id
                ?? $shipment->origin_sub_branch_id,

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

        $data =
            array_intersect_key(
                $data,
                array_flip($columns)
            );

        DB::table($table)
            ->insert($data);
    }

    private function shipmentColumns(): array
    {
        return array_flip(
            DB::getSchemaBuilder()
                ->getColumnListing('shipments')
        );
    }

    private function filterShipmentColumns(
        array $data
    ): array {
        return array_intersect_key(
            $data,
            $this->shipmentColumns()
        );
    }

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

                if (
                    array_key_exists(
                        'merchant_status',
                        $this->shipmentColumns()
                    )
                ) {
                    $shipment->merchant_status =
                        CourierStatus::merchantStatus(
                            $status
                        );
                }

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