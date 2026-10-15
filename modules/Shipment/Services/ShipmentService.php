<?php

declare(strict_types=1);

namespace Modules\Shipment\Services;

use App\Support\CourierStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Merchant\Models\Merchant;
use Modules\Pickup\Services\GatewayPickupService;
use Modules\Pickup\Services\PickupService;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Services\BranchAssignmentService;
use Modules\Shipment\Services\MerchantPickupLocationResolver;
use Modules\Shipment\Services\ShipmentNumberService;
use Modules\Shipment\Services\CheckoutDeliveryChargeResolver;

final class ShipmentService
{
    public function __construct(
        private readonly MerchantPickupLocationResolver $pickupResolver,
        private readonly BranchAssignmentService $branchAssignment,
        private readonly ShipmentNumberService $shipmentNumberService,
        private readonly GatewayPickupService $pickupService,
        private readonly ShipmentRoutePlannedCallbackService $routePlannedCallback,
        private readonly CheckoutDeliveryChargeResolver $checkoutDeliveryChargeResolver,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | GATEWAY SHIPMENT
    |--------------------------------------------------------------------------
    |
    | Store Manager -> Tukaatu Express
    |
    | IMPORTANT:
    |
    | Shipment creation NEVER creates a pickup.
    |
    | If an active pickup already exists:
    |
    |     shipment -> active pickup
    |
    | If no active pickup exists:
    |
    |     shipment -> AWAITING_PICKUP
    |
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

        if (! $merchant->isActive()) {
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
                | IDEMPOTENCY
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
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    /*
                    |--------------------------------------------------------------------------
                    | Retry-safe reconciliation.
                    |--------------------------------------------------------------------------
                    |
                    | If the first request created the shipment but the caller
                    | retries, we still attempt to attach it to an active pickup.
                    |
                    */

                    if (
                        ! $existing->self_drop
                        &&
                        $existing->pickup_location_id
                        &&
                        $existing->status
                        === CourierStatus::AWAITING_PICKUP
                    ) {
                        $this->pickupService
                            ->attachShipmentToOpenPickup(
                                merchantId:
                                    (int) $merchant->id,

                                pickupLocationId:
                                    (int) $existing->pickup_location_id,

                                shipment:
                                    $existing,
                            );
                    }

                    return $existing->fresh([
                        'pickupRequests',
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | PICKUP LOCATION
                |--------------------------------------------------------------------------
                */

                $pickupLocation =
                    $this->pickupResolver->resolve(
                        $merchant,
                        $data
                    );

                /*
                |--------------------------------------------------------------------------
                | SELF DROP
                |--------------------------------------------------------------------------
                */

                $selfDrop = filter_var(
                    $data['self_drop'] ?? false,
                    FILTER_VALIDATE_BOOLEAN
                );

                if (
                    ! $pickupLocation
                    &&
                    ! $selfDrop
                ) {
                    throw ValidationException::withMessages([
                        'pickup_location_id' => [
                            'Pickup location could not be resolved.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | OWNERSHIP
                |--------------------------------------------------------------------------
                */

                if (
                    $pickupLocation
                    &&
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
                | PICKUP COORDINATES
                |--------------------------------------------------------------------------
                */

                $pickupCoordinates =
                    $this->branchAssignment->pickupCoordinates(
                        $pickupLocation,
                        $merchant
                    );

                /*
                |--------------------------------------------------------------------------
                | ORIGIN
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
                | DESTINATION
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
                | ROUTE
                |--------------------------------------------------------------------------
                */

                $deliveryLat = isset($data['delivery_lat']) && is_numeric($data['delivery_lat']) ? (float) $data['delivery_lat'] : null;
                $deliveryLng = isset($data['delivery_lng']) && is_numeric($data['delivery_lng']) ? (float) $data['delivery_lng'] : null;
                $serviceType = (string) ($data['service_type'] ?? 'standard');

                $route =
                    $this->branchAssignment->buildRoute(
                        $origin,
                        $destination,
                        $deliveryLat,
                        $deliveryLng,
                        $serviceType
                    );

                /*
                |--------------------------------------------------------------------------
                | TRACKING NUMBER
                |--------------------------------------------------------------------------
                */

                $trackingNumber =
                    $this->shipmentNumberService
                        ->generate();

                /*
                |--------------------------------------------------------------------------
                | PRODUCTS
                |--------------------------------------------------------------------------
                */

                $packetProducts =
                    $this->buildPacketProducts(
                        $products,
                        $trackingNumber
                    );

                /*
                |--------------------------------------------------------------------------
                | CHARGES (pod amount, delivery charge, total collectable)
                |--------------------------------------------------------------------------
                */

                $charges = $this->resolveCharges($data, $pickupCoordinates);

                if (! empty($charges['breakdown']) && empty($data['delivery_charge_breakdown'])) {
                    $data['delivery_charge_breakdown'] = $charges['breakdown'];
                }

                /*
                |--------------------------------------------------------------------------
                | SHIPMENT DATA
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
                        $pickupLocation?->id,

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

                    /*
                    |--------------------------------------------------------------------------
                    | POD amount + delivery charge + total collectable
                    |--------------------------------------------------------------------------
                    |
                    | pod_amount: the goods value collected on delivery (0 for
                    |   prepaid).
                    | delivery_charge: the shipping fee.
                    | total_collectable_amount: what the rider physically collects
                    |   from the customer = pod_amount + (delivery_charge only when
                    |   the customer pays the delivery charge).
                    |--------------------------------------------------------------------------
                    */
                    'pod_amount' =>
                        $charges['pod_amount'],

                    'delivery_charge' =>
                        $charges['delivery_charge'],

                    'pod_charge' =>
                        $charges['pod_charge'] ?? ($data['pod_charge'] ?? 0),

                    'delivery_charge_breakdown' =>
                        $charges['breakdown'] ?? ($data['delivery_charge_breakdown'] ?? null),

                    'total_collectable_amount' =>
                        $charges['total_collectable_amount'],

                    'delivery_charge_paid_by' =>
                        $charges['paid_by'] ?? ($data['delivery_charge_paid_by'] ?? 'customer'),

                    'delivery_free_by' =>
                        $charges['free_by'] ?? \Modules\Settlement\Services\SettlementWorkflowService::resolveFreeBy($data, true),

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
                | OPTIONAL COLUMNS
                |--------------------------------------------------------------------------
                */

                $columns =
                    $this->shipmentColumns();

                if (
                    array_key_exists(
                        'requires_transfer',
                        $columns
                    )
                ) {
                    $shipmentData['requires_transfer'] =
                        (bool) (
                            $route['requires_transfer']
                            ?? false
                        );
                }

                if (array_key_exists('route_id', $columns) && !empty($route['route_id'])) {
                    if (\Illuminate\Support\Facades\DB::table('delivery_routes')->where('id', $route['route_id'])->exists()) {
                        $shipmentData['route_id'] = $route['route_id'];
                    }
                }

                if (array_key_exists('transfer_route_id', $columns) && !empty($route['transfer_route_id'])) {
                    $shipmentData['transfer_route_id'] = $route['transfer_route_id'];
                }

                if (array_key_exists('path_text', $columns) && !empty($route['path_text'])) {
                    $shipmentData['path_text'] = $route['path_text'];
                }

                if (
                    array_key_exists(
                        'route_distance_km',
                        $columns
                    )
                ) {
                    $shipmentData['route_distance_km'] =
                        $route['total_distance_km'] ?? $route['distance_km'] ?? 0;
                }

                if (array_key_exists('last_mile_fee', $columns)) {
                    $shipmentData['last_mile_fee'] = round((float) data_get($charges, 'breakdown.breakdown.extra_delivery_distance.total', 0), 2);
                }

                if (array_key_exists('route_fee', $columns)) {
                    $shipmentData['route_fee'] = round((float) data_get($charges, 'breakdown.breakdown.route_base_rate.total', 0), 2);
                }

                /*
                |--------------------------------------------------------------------------
                | SCHEMA PROTECTION
                |--------------------------------------------------------------------------
                */

                $shipmentData =
                    $this->filterShipmentColumns(
                        $shipmentData
                    );

                /*
                |--------------------------------------------------------------------------
                | CREATE SHIPMENT
                |--------------------------------------------------------------------------
                */

                $shipment =
                    Shipment::query()->create(
                        $shipmentData
                    );

                /*
                |--------------------------------------------------------------------------
                | INITIAL TRACKING
                |--------------------------------------------------------------------------
                */

                $this->createTrackingEvent(
                    shipment: $shipment,
                    oldStatus: null,
                    newStatus:
                        CourierStatus::AWAITING_PICKUP,
                    description:
                        'Shipment created successfully. Awaiting pickup.'
                );

                /*
                |--------------------------------------------------------------------------
                | SEND ROUTE PLANNED CALLBACK
                |--------------------------------------------------------------------------
                |
                | Notify merchant about shipment route: origin, transits, destination,
                | with real distances and estimated hours from transfer lanes.
                |
                */

                $this->routePlannedCallback->sendRoutePlanned($shipment);

                /*
                |--------------------------------------------------------------------------
                | AUTOMATIC ACTIVE PICKUP BATCHING
                |--------------------------------------------------------------------------
                |
                | This DOES NOT create a pickup.
                |
                | It only searches for an existing active pickup.
                |
                */

                if (
                    $pickupLocation
                    &&
                    ! $selfDrop
                ) {
                    $this->pickupService
                        ->attachShipmentToOpenPickup(
                            merchantId:
                                (int) $merchant->id,

                            pickupLocationId:
                                (int) $pickupLocation->id,

                            shipment:
                                $shipment,
                        );
                }

                return $shipment->fresh([
                    'pickupRequests',
                ]);
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | MANUAL SHIPMENT CREATION
    |--------------------------------------------------------------------------
    */

    public function create(
        array $data,
        ?int $userId = null,
        ?int $merchantId = null,
        string $source = 'manual'
    ): Shipment {
        if ($merchantId !== null) {
            $data['merchant_id'] = $merchantId;
        }

        if (
            ! empty($data['merchant_id'])
            &&
            isset($data['pickup_location_id'])
            &&
            isset($data['delivery_lat'])
            &&
            isset($data['delivery_lng'])
            &&
            isset($data['packet'])
        ) {
            return $this->createFromGateway(
                (int) $data['merchant_id'],
                $data
            );
        }

        return DB::transaction(
            function () use (
                $data,
                $userId,
                $source
            ): Shipment {
                $trackingNumber =
                    $data['tracking_number']
                    ??
                    $this->shipmentNumberService
                        ->generate();

                $data['delivery_free_by'] = \Modules\Settlement\Services\SettlementWorkflowService::resolveFreeBy($data, true);

                $shipmentData = array_merge(
                    $data,
                    [
                        'tracking_number' =>
                            $trackingNumber,

                        'order_source' =>
                            $source,

                        'status' =>
                            $data['status']
                            ??
                            CourierStatus::AWAITING_PICKUP,
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

                /*
                |--------------------------------------------------------------------------
                | Manual creation can also join an active pickup when the
                | necessary merchant/location information is available.
                |--------------------------------------------------------------------------
                */

                if (
                    $shipment->merchant_id
                    &&
                    $shipment->pickup_location_id
                    &&
                    ! $shipment->self_drop
                    &&
                    $shipment->status
                    === CourierStatus::AWAITING_PICKUP
                ) {
                    $this->pickupService
                        ->attachShipmentToOpenPickup(
                            merchantId:
                                (int) $shipment->merchant_id,

                            pickupLocationId:
                                (int) $shipment->pickup_location_id,

                            shipment:
                                $shipment,
                        );
                }

                return $shipment->fresh([
                    'pickupRequests',
                ]);
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

        $columns =
            DB::getSchemaBuilder()
                ->getColumnListing($table);

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

    /*
    |--------------------------------------------------------------------------
    | Resolve charges for a shipment.
    |
    | pod_amount:
    |   Goods value collected on delivery. 0 for prepaid. For POD it is read
    |   from pod_amount (or the nested payment.pod_amount shape).
    |
    | delivery_charge:
    |   The shipping fee from PricingEngine final_price or explicit quote
    |   delivery_charge. Config base_fee (80) fallback is disabled.
    |
    | total_collectable_amount:
    |   What the rider physically collects from the customer =
    |     pod_amount + (delivery_charge only when the customer pays it).
    |
    | @return array{pod_amount: float, delivery_charge: float, total_collectable_amount: float}
    |--------------------------------------------------------------------------
    */
    private function resolveCharges(array $data, array $pickupCoordinates = []): array
    {
        $type = strtolower((string) ($data['payment_type'] ?? 'prepaid'));

        $podAmount = 0.0;

        if ($type === 'pod') {
            // Prefer an explicit pod_amount; otherwise fall back to the
            // packet's collectable value: declared_value, else the sum of
            // product (unit_price x quantity). Ensures a POD order always
            // stores a non-zero amount to collect.
            $explicit = $data['pod_amount']
                ?? data_get($data, 'payment.pod_amount')
                ?? data_get($data, 'packet.pod_amount');

            if ($explicit !== null) {
                $podAmount = round((float) $explicit, 2);
            } else {
                $podAmount = round((float) $this->packetCollectableValue($data), 2);
            }
        }

        $priced = $this->checkoutDeliveryChargeResolver->resolveFromPayload(
            $data,
            $pickupCoordinates,
            preferExplicitQuote: true
        );
        $deliveryCharge = round((float) $priced['delivery_charge'], 2);

        $freeBy = \Modules\Settlement\Services\SettlementWorkflowService::normalizeFreeBy(
            $data['delivery_free_by'] ?? data_get($data, 'payment.delivery_free_by') ?? 'none'
        );

        $defaultPaidBy = in_array($freeBy, ['marketplace', 'admin'], true)
            ? 'admin'
            : (in_array($freeBy, ['store', 'merchant'], true) ? 'merchant' : 'customer');

        $paidBy = strtolower((string) ($data['delivery_charge_paid_by'] ?? $defaultPaidBy));

        $customerPaysDelivery = ($freeBy === 'none' && $paidBy === 'customer');

        $totalCollectable = $podAmount
            + ($customerPaysDelivery ? $deliveryCharge : 0.0);

        return [
            'pod_amount' => $podAmount,
            'delivery_charge' => $deliveryCharge,
            'total_collectable_amount' => round($totalCollectable, 2),
            'breakdown' => $priced['breakdown'] ?? null,
            'pricing_source' => $priced['source'] ?? null,
            'pod_charge' => round((float) ($priced['pod_charge'] ?? $data['pod_charge'] ?? 0), 2),
            'paid_by' => $paidBy,
            'free_by' => $freeBy,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Collectable value of the packet.
    |
    | Uses packet.declared_value when present, otherwise the sum of
    | product (unit_price x quantity). Used as the POD amount fallback.
    |--------------------------------------------------------------------------
    */
    private function packetCollectableValue(array $data): float
    {
        $declared = data_get($data, 'packet.declared_value');

        if ($declared !== null && (float) $declared > 0) {
            return (float) $declared;
        }

        $products = data_get($data, 'packet.products', []);

        if (! is_array($products)) {
            return 0.0;
        }

        $sum = 0.0;

        foreach ($products as $product) {
            $price = (float) ($product['unit_price'] ?? 0);
            $qty = (int) ($product['quantity'] ?? 1);
            $sum += $price * max($qty, 1);
        }

        return $sum;
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
                $shipment =
                    Shipment::query()
                        ->lockForUpdate()
                        ->findOrFail(
                            $shipment->id
                        );

                $oldStatus =
                    $shipment->status;

                $shipment->status =
                    $status;

                $shipment->merchant_status =
                    CourierStatus::merchantStatus(
                        $status
                    );

                if (
                    $status
                    === CourierStatus::DELIVERED
                ) {
                    $shipment->delivered_at =
                        now();
                }

                if (
                    $status
                    === CourierStatus::CANCELLED
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

    /*
    |--------------------------------------------------------------------------
    | PREVIEW DELIVERY LOCATION RECALCULATION
    |--------------------------------------------------------------------------
    */

    public function previewDeliveryLocationRecalculation(
        Shipment $shipment,
        array $data
    ): array {
        $deliveryLat = (float) ($data['delivery_lat'] ?? $data['delivery_latitude'] ?? $shipment->delivery_lat);
        $deliveryLng = (float) ($data['delivery_lng'] ?? $data['delivery_longitude'] ?? $shipment->delivery_lng);

        if ($deliveryLat == 0.0 && $deliveryLng == 0.0) {
            throw ValidationException::withMessages([
                'delivery_lat' => ['Valid delivery coordinates are required for distance recalculation.'],
            ]);
        }

        $deliveryCity = $data['delivery_city'] ?? $data['receiver_city'] ?? $shipment->delivery_city ?? $shipment->receiver_city;
        $deliveryArea = $data['delivery_area'] ?? $data['receiver_area'] ?? $shipment->delivery_area ?? $shipment->receiver_area;
        $deliveryAddress = $data['delivery_address'] ?? $data['receiver_address'] ?? $shipment->delivery_address ?? $shipment->receiver_address;

        // 1. Resolve destination branch based on new coordinates and area
        $destination = $this->branchAssignment->resolveDestination([
            'latitude' => $deliveryLat,
            'longitude' => $deliveryLng,
            'city' => $deliveryCity,
            'area' => $deliveryArea,
        ]);

        // 2. Resolve pickup coordinates
        $pickupLat = (float) ($shipment->pickup_lat ?? $shipment->pickup_latitude ?? 0);
        $pickupLng = (float) ($shipment->pickup_lng ?? $shipment->pickup_longitude ?? 0);

        if ($pickupLat == 0.0 || $pickupLng == 0.0) {
            if ($shipment->pickup_location_id) {
                $loc = DB::table('merchant_pickup_locations')
                    ->where('id', $shipment->pickup_location_id)
                    ->first();
                if ($loc) {
                    $pickupLat = (float) ($loc->latitude ?? $loc->lat ?? 0);
                    $pickupLng = (float) ($loc->longitude ?? $loc->lng ?? 0);
                }
            }
        }

        if ($pickupLat == 0.0 || $pickupLng == 0.0) {
            $originBranch = $shipment->originSubBranch ?? $shipment->originBranch;
            if ($originBranch) {
                $pickupLat = (float) ($originBranch->latitude ?? $originBranch->office_latitude ?? 0);
                $pickupLng = (float) ($originBranch->longitude ?? $originBranch->office_longitude ?? 0);
            }
        }

        $weight = (float) (
            $shipment->chargeable_weight > 0
                ? $shipment->chargeable_weight
                : ($shipment->actual_weight > 0 ? $shipment->actual_weight : ($shipment->weight ?? 1.5))
        );

        $chargePayload = [
            'merchant_id'             => $shipment->merchant_id,
            'pickup_lat'              => $pickupLat,
            'pickup_lng'              => $pickupLng,
            'delivery_lat'            => $deliveryLat,
            'delivery_lng'            => $deliveryLng,
            'weight'                  => max($weight, 0.1),
            'service_type'            => $shipment->service_type ?? 'standard',
            'parcel_type'             => $shipment->parcel_type ?? 'non_fragile',
            'fragile'                 => (bool) $shipment->fragile,
            'payment_type'            => $shipment->payment_type ?? 'prepaid',
            'pod_amount'              => (float) ($shipment->pod_amount ?? 0),
            'delivery_charge_paid_by' => $shipment->delivery_charge_paid_by ?? 'merchant',
            'packet_products'         => $shipment->packet_products ?? [],
        ];

        $priced = $this->checkoutDeliveryChargeResolver->resolveFromPayload(
            $chargePayload,
            ['lat' => $pickupLat, 'lng' => $pickupLng],
            preferExplicitQuote: false
        );

        $newDeliveryCharge = round((float) $priced['delivery_charge'], 2);
        $oldDeliveryCharge = round((float) ($shipment->delivery_charge ?? $shipment->delivery_fee ?? 0), 2);
        $chargeDifference = round($newDeliveryCharge - $oldDeliveryCharge, 2);

        $paidBy = strtolower((string) ($shipment->delivery_charge_paid_by ?? 'merchant'));
        $podAmount = (float) ($shipment->pod_amount ?? 0);

        $oldCollectable = (float) ($shipment->total_collectable_amount ?? $shipment->total_collectable ?? ($podAmount + ($paidBy === 'customer' ? $oldDeliveryCharge : 0)));
        $newCollectable = round($podAmount + ($paidBy === 'customer' ? $newDeliveryCharge : 0), 2);

        // Calculate distance
        $distanceKm = data_get($priced, 'breakdown.delivery_branch.distance_km');
        if ($distanceKm === null && $pickupLat && $pickupLng) {
            $distanceKm = $this->branchAssignment->distanceKm($pickupLat, $pickupLng, $deliveryLat, $deliveryLng);
        }

        return [
            'old_delivery_charge'          => $oldDeliveryCharge,
            'new_delivery_charge'          => $newDeliveryCharge,
            'charge_difference'            => $chargeDifference,
            'old_total_collectable_amount' => $oldCollectable,
            'new_total_collectable_amount' => $newCollectable,
            'delivery_charge_paid_by'      => $shipment->delivery_charge_paid_by,
            'destination_branch'           => [
                'branch_id'    => $destination['branch_id'] ?? null,
                'sub_branch_id'=> $destination['sub_branch_id'] ?? null,
                'name'         => $destination['name'] ?? null,
                'branch_name'  => $destination['branch_name'] ?? null,
                'distance_km'  => $destination['distance_km'] ?? null,
            ],
            'distance_km'                  => $distanceKm !== null ? round((float) $distanceKm, 2) : null,
            'breakdown'                    => $priced['breakdown'] ?? null,
            'pod_charge'                   => round((float) ($priced['pod_charge'] ?? $shipment->pod_charge ?? 0), 2),
            'pricing_source'               => $priced['source'] ?? null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE CUSTOMER DELIVERY LOCATION & RECALCULATE
    |--------------------------------------------------------------------------
    */

    public function updateDeliveryLocation(
        Shipment $shipment,
        array $data,
        ?int $userId = null
    ): Shipment {
        return DB::transaction(function () use ($shipment, $data, $userId) {
            $shipment = Shipment::query()
                ->lockForUpdate()
                ->findOrFail($shipment->id);

            $status = strtolower((string) $shipment->status);
            if (in_array($status, [CourierStatus::DELIVERED, CourierStatus::CANCELLED], true)) {
                throw ValidationException::withMessages([
                    'status' => ["Cannot update delivery location for a {$shipment->status} shipment."],
                ]);
            }

            $preview = $this->previewDeliveryLocationRecalculation($shipment, $data);

            $deliveryLat = (float) ($data['delivery_lat'] ?? $data['delivery_latitude'] ?? $shipment->delivery_lat);
            $deliveryLng = (float) ($data['delivery_lng'] ?? $data['delivery_longitude'] ?? $shipment->delivery_lng);

            $deliveryAddress = $data['delivery_address'] ?? $data['receiver_address'] ?? $shipment->delivery_address ?? $shipment->receiver_address;
            $deliveryCity = $data['delivery_city'] ?? $data['receiver_city'] ?? $shipment->delivery_city ?? $shipment->receiver_city;
            $deliveryArea = $data['delivery_area'] ?? $data['receiver_area'] ?? $shipment->delivery_area ?? $shipment->receiver_area;
            $receiverName = $data['receiver_name'] ?? $data['customer_name'] ?? $shipment->receiver_name ?? $shipment->customer_name;
            $receiverPhone = $data['receiver_phone'] ?? $data['customer_phone'] ?? $shipment->receiver_phone ?? $shipment->customer_phone;
            $receiverEmail = $data['receiver_email'] ?? $data['customer_email'] ?? $shipment->receiver_email ?? $shipment->customer_email;

            $destBranchId = $preview['destination_branch']['branch_id'] ?? $shipment->destination_branch_id;
            $destSubBranchId = $preview['destination_branch']['sub_branch_id'] ?? $shipment->destination_sub_branch_id;

            $updatePayload = [
                'delivery_lat'              => $deliveryLat,
                'delivery_lng'              => $deliveryLng,
                'delivery_latitude'         => $deliveryLat,
                'delivery_longitude'        => $deliveryLng,
                'delivery_address'          => $deliveryAddress,
                'receiver_address'          => $deliveryAddress,
                'delivery_city'             => $deliveryCity,
                'receiver_city'             => $deliveryCity,
                'delivery_area'             => $deliveryArea,
                'receiver_area'             => $deliveryArea,
                'receiver_name'             => $receiverName,
                'customer_name'             => $receiverName,
                'receiver_phone'            => $receiverPhone,
                'customer_phone'            => $receiverPhone,
                'receiver_email'            => $receiverEmail,
                'customer_email'            => $receiverEmail,
                'destination_branch_id'     => $destBranchId,
                'destination_sub_branch_id' => $destSubBranchId,
                'delivery_charge'           => $preview['new_delivery_charge'],
                'delivery_fee'              => $preview['new_delivery_charge'],
                'delivery_charge_breakdown' => $preview['breakdown'],
                'pod_charge'                => $preview['pod_charge'],
                'total_collectable_amount'  => $preview['new_total_collectable_amount'],
                'total_collectable'         => $preview['new_total_collectable_amount'],
            ];

            if ($preview['distance_km'] !== null) {
                $updatePayload['route_distance_km'] = $preview['distance_km'];
            }

            // Filter columns to those existing in the table
            $columns = $this->shipmentColumns();
            $filtered = array_intersect_key($updatePayload, $columns);
            $shipment->update($filtered);

            // Synchronize secondary tables if they exist
            if (DB::getSchemaBuilder()->hasTable('shipment_charges')) {
                DB::table('shipment_charges')
                    ->where('shipment_id', $shipment->id)
                    ->update([
                        'delivery_charge' => $preview['new_delivery_charge'],
                        'breakdown'       => json_encode($preview['breakdown']),
                        'updated_at'      => now(),
                    ]);
            }

            if (DB::getSchemaBuilder()->hasTable('pod_transactions')) {
                DB::table('pod_transactions')
                    ->where('shipment_id', $shipment->id)
                    ->update([
                        'delivery_charge' => $preview['new_delivery_charge'],
                        'total_collected' => $preview['new_total_collectable_amount'],
                        'updated_at'      => now(),
                    ]);
            }

            // Create tracking event recording location change & charge recalculation
            $oldFee = number_format($preview['old_delivery_charge'], 2);
            $newFee = number_format($preview['new_delivery_charge'], 2);
            $addrText = $deliveryAddress ? "to '{$deliveryAddress}'" : 'coordinates';
            $description = "Delivery location updated {$addrText}. Delivery charge recalculated from NPR {$oldFee} to NPR {$newFee}.";

            $this->createTrackingEvent(
                shipment: $shipment,
                oldStatus: $shipment->status,
                newStatus: $shipment->status,
                description: $description
            );

            return $shipment->fresh();
        });
    }
}

