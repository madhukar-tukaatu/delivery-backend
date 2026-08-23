<?php

declare(strict_types=1);

namespace Modules\Shipment\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Merchant\Models\Merchant;
use Modules\Shipment\Models\Shipment;
use App\Support\CourierStatus;

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
     * One API request represents:
     *
     * Store
     *   └── Customer Order
     *         └── One Packet
     *               ├── Product 1
     *               ├── Product 2
     *               └── Product N
     *
     * Tukaatu creates one shipment/tracking number for the packet.
     */
    public function createFromGateway(
        int $merchantId,
        array $data
    ): Shipment {

        /*
        |--------------------------------------------------------------------------
        | Merchant
        |--------------------------------------------------------------------------
        */

        $merchant = Merchant::query()
            ->find($merchantId);

        if (! $merchant) {
            throw ValidationException::withMessages([
                'merchant' => 'Authenticated merchant was not found.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Merchant must be active
        |--------------------------------------------------------------------------
        */

        if ($merchant->status !== 'active') {
            throw ValidationException::withMessages([
                'merchant' => 'Merchant account is not active.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Transaction
        |--------------------------------------------------------------------------
        */

        return DB::transaction(function () use (
            $merchant,
            $data
        ): Shipment {

            /*
            |--------------------------------------------------------------------------
            | Prevent duplicate merchant order
            |--------------------------------------------------------------------------
            */

            $existing = Shipment::query()
                ->where('merchant_id', $merchant->id)
                ->where(
                    'merchant_order_id',
                    $data['merchant_order_id']
                )
                ->first();

            if ($existing) {
                return $existing->fresh();
            }

            /*
            |--------------------------------------------------------------------------
            | Resolve pickup location
            |--------------------------------------------------------------------------
            */

            $pickupLocation = $this->pickupResolver->resolve(
                $merchant,
                $data
            );

            if (! $pickupLocation) {
                throw ValidationException::withMessages([
                    'pickup_location_id' =>
                        'Pickup location does not belong to the authenticated merchant.',
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
            | Resolve origin branch
            |--------------------------------------------------------------------------
            */

            $origin =
                $this->branchAssignment->resolveOrigin(
                    $merchant,
                    $pickupLocation
                );

            /*
            |--------------------------------------------------------------------------
            | Resolve destination branch
            |--------------------------------------------------------------------------
            */

            $destination =
                $this->branchAssignment->resolveDestination([
                    'latitude' => $data['delivery_lat'],
                    'longitude' => $data['delivery_lng'],
                    'city' => $data['delivery_city'] ?? null,
                    'area' => $data['delivery_area'] ?? null,
                ]);

            /*
            |--------------------------------------------------------------------------
            | Validate origin
            |--------------------------------------------------------------------------
            */

            if (! $origin['branch_id']) {
                throw ValidationException::withMessages([
                    'pickup_location_id' =>
                        'Unable to determine origin branch for this pickup location.',
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Validate destination
            |--------------------------------------------------------------------------
            */

            if (! $destination['branch_id']) {
                throw ValidationException::withMessages([
                    'delivery_lat' =>
                        'Unable to determine destination branch for this delivery location.',
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Build route
            |--------------------------------------------------------------------------
            */

            $route =
                $this->branchAssignment->buildRoute(
                    $origin,
                    $destination
                );

            /*
            |--------------------------------------------------------------------------
            | Packet
            |--------------------------------------------------------------------------
            */

            $packet = $data['packet'];

            /*
            |--------------------------------------------------------------------------
            | Products
            |--------------------------------------------------------------------------
            */

            $products = $packet['products'];

            /*
            |--------------------------------------------------------------------------
            | Tracking number
            |--------------------------------------------------------------------------
            */

            $trackingNumber =
                $this->shipmentNumberService->generate();

            /*
            |--------------------------------------------------------------------------
            | Shipment data
            |--------------------------------------------------------------------------
            */

            $shipmentData = [

                /*
                |------------------------------------------------------------------
                | Identity
                |------------------------------------------------------------------
                */

                'tracking_number' =>
                    $trackingNumber,

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
            | Only existing DB columns
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

            $shipment = Shipment::query()
                ->create($shipmentData);

            /*
            |--------------------------------------------------------------------------
            | Store packet/products if packet tables exist
            |--------------------------------------------------------------------------
            */

            $this->storePacketProducts(
                $shipment,
                $packet,
                $products,
                $trackingNumber
            );

            /*
            |--------------------------------------------------------------------------
            | Initial tracking event
            |--------------------------------------------------------------------------
            */

            $this->createTrackingEvent(
                shipment: $shipment,
                oldStatus: null,
                newStatus: CourierStatus::AWAITING_PICKUP,
                description: 'Shipment created successfully. Awaiting pickup.'
            );

            /*
            |--------------------------------------------------------------------------
            | Return shipment
            |--------------------------------------------------------------------------
            */

            return $shipment->fresh();
        });
    }

    /**
     * Store packet and product details when corresponding tables exist.
     *
     * This method is intentionally defensive because your current
     * shipments table may not yet have packet/product tables.
     */
    private function storePacketProducts(
        Shipment $shipment,
        array $packet,
        array $products,
        string $trackingNumber
    ): void {

        /*
        |--------------------------------------------------------------------------
        | Packet table
        |--------------------------------------------------------------------------
        */

        if (DB::getSchemaBuilder()->hasTable('shipment_packets')) {

            $packetColumns =
                DB::getSchemaBuilder()
                    ->getColumnListing('shipment_packets');

            $packetData = [
                'shipment_id' =>
                    $shipment->id,

                'tracking_number' =>
                    $trackingNumber,

                'description' =>
                    $packet['description'] ?? null,

                'quantity' =>
                    $packet['quantity'],

                'weight' =>
                    $packet['weight'],

                'declared_value' =>
                    $packet['declared_value'],

                'parcel_type' =>
                    $packet['parcel_type'],

                'fragile' =>
                    $packet['fragile'],

                'created_at' =>
                    now(),

                'updated_at' =>
                    now(),
            ];

            $packetData =
                array_intersect_key(
                    $packetData,
                    array_flip($packetColumns)
                );

            DB::table('shipment_packets')
                ->insert($packetData);
        }

        /*
        |--------------------------------------------------------------------------
        | Product table
        |--------------------------------------------------------------------------
        */

        if (! DB::getSchemaBuilder()->hasTable('shipment_products')) {
            return;
        }

        $productColumns =
            DB::getSchemaBuilder()
                ->getColumnListing('shipment_products');

        foreach ($products as $index => $product) {

            /*
            |--------------------------------------------------------------------------
            | Product tracking reference
            |--------------------------------------------------------------------------
            |
            | Example:
            |
            | TKT-20260823-852101-P1
            | TKT-20260823-852101-P2
            |
            */

            $productTrackingNumber =
                $trackingNumber . '-P' . ($index + 1);

            $productData = [

                'shipment_id' =>
                    $shipment->id,

                'tracking_number' =>
                    $productTrackingNumber,

                'product_id' =>
                    $product['product_id'] ?? null,

                'name' =>
                    $product['name'],

                'quantity' =>
                    $product['quantity'],

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
                    array_flip($productColumns)
                );

            DB::table('shipment_products')
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

        $table = 'shipment_tracking_events';

        if (! DB::getSchemaBuilder()->hasTable($table)) {
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

        DB::table($table)->insert($data);
    }

    /**
     * Only use columns that actually exist in shipments table.
     */
    private function filterShipmentColumns(
        array $data
    ): array {

        $columns =
            DB::getSchemaBuilder()
                ->getColumnListing('shipments');

        return array_intersect_key(
            $data,
            array_flip($columns)
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
                    $note ?? 'Shipment status updated.'
                );

                return $shipment->fresh();
            }
        );
    }
}