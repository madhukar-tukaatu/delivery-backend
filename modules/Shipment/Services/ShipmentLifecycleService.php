<?php

namespace Modules\Shipment\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShipmentLifecycleService
{
    public function __construct(
        private TrackingNumberService $trackingNumberService,
        private BranchAssignmentService $branchAssignmentService,
        private FareQuoteService $fareQuoteService,
    ) {}

    public function quote(int $merchantId, array $payload): array
    {
        $pickupLocationId = data_get($payload, 'pickup_location_id');
        $origin = $this->branchAssignmentService->resolveOrigin($merchantId, $pickupLocationId);
        $destination = $this->branchAssignmentService->resolveDestination($payload);
        $route = $this->branchAssignmentService->routeSummary($origin, $destination);
        $fare = $this->fareQuoteService->quote($payload, $origin, $destination);

        return [
            'merchant_id' => $merchantId,
            'origin' => $origin,
            'destination' => $destination,
            'route' => $route,
            'fare' => $fare,
        ];
    }

    public function create(int $merchantId, array $payload, string $source, ?int $actorId = null, ?string $idempotencyKey = null): array
    {
        $orderReference = data_get($payload, 'order_reference');
        $lockKey = 'shipment:create:' . $merchantId . ':' . ($idempotencyKey ?: $orderReference ?: md5(json_encode($payload)));

        return Cache::lock($lockKey, 15)->block(5, function () use ($merchantId, $payload, $source, $actorId, $idempotencyKey) {
            if ($idempotencyKey) {
                $existing = DB::table('merchant_api_idempotency_keys')
                    ->where('merchant_id', $merchantId)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing?->response_payload) {
                    return json_decode($existing->response_payload, true);
                }
            }

            $this->guardMerchant($merchantId);

            $quote = $this->quote($merchantId, $payload);

            $result = DB::transaction(function () use ($merchantId, $payload, $source, $actorId, $quote) {
                $trackingNumber = $this->trackingNumberService->generate();
                $fare = $quote['fare'];
                $origin = $quote['origin'];
                $destination = $quote['destination'];

                $paymentType = data_get($payload, 'payment.type', data_get($payload, 'payment_type', 'prepaid'));
                $codAmount = (float) ($fare['cod_amount'] ?? 0);

                $shipmentId = DB::table('shipments')->insertGetId([
                    'tracking_number' => $trackingNumber,
                    'merchant_id' => $merchantId,
                    'pickup_location_id' => data_get($payload, 'pickup_location_id'),
                    'source' => $source,
                    'external_order_id' => data_get($payload, 'external_order_id'),
                    'order_reference' => data_get($payload, 'order_reference'),
                    'receiver_name' => data_get($payload, 'customer.name', data_get($payload, 'receiver_name')),
                    'receiver_phone' => data_get($payload, 'customer.phone', data_get($payload, 'receiver_phone')),
                    'receiver_email' => data_get($payload, 'customer.email', data_get($payload, 'receiver_email')),
                    'receiver_address' => data_get($payload, 'delivery.address', data_get($payload, 'receiver_address')),
                    'receiver_city' => data_get($payload, 'delivery.city', data_get($payload, 'receiver_city')),
                    'receiver_area' => data_get($payload, 'delivery.area', data_get($payload, 'receiver_area')),
                    'receiver_latitude' => data_get($payload, 'delivery.latitude', data_get($payload, 'receiver_latitude')),
                    'receiver_longitude' => data_get($payload, 'delivery.longitude', data_get($payload, 'receiver_longitude')),
                    'package_type' => data_get($payload, 'package.type', data_get($payload, 'package_type', 'parcel')),
                    'package_description' => data_get($payload, 'package.description', data_get($payload, 'package_description')),
                    'actual_weight' => $fare['actual_weight'],
                    'volumetric_weight' => $fare['volumetric_weight'],
                    'chargeable_weight' => $fare['chargeable_weight'],
                    'length_cm' => data_get($payload, 'package.length_cm', data_get($payload, 'length_cm', 0)),
                    'width_cm' => data_get($payload, 'package.width_cm', data_get($payload, 'width_cm', 0)),
                    'height_cm' => data_get($payload, 'package.height_cm', data_get($payload, 'height_cm', 0)),
                    'pieces' => data_get($payload, 'package.pieces', data_get($payload, 'pieces', 1)),
                    'declared_value' => data_get($payload, 'package.value', data_get($payload, 'declared_value', 0)),
                    'payment_type' => $paymentType,
                    'cod_amount' => $codAmount,
                    'delivery_charge' => $fare['delivery_charge'],
                    'delivery_charge_paid_by' => $fare['delivery_charge_paid_by'],
                    'total_collectable' => $fare['total_collectable'],
                    'origin_branch_id' => $origin['branch_id'],
                    'origin_sub_branch_id' => $origin['sub_branch_id'],
                    'destination_branch_id' => $destination['branch_id'],
                    'destination_sub_branch_id' => $destination['sub_branch_id'],
                    'current_branch_id' => $origin['branch_id'],
                    'current_sub_branch_id' => $origin['sub_branch_id'],
                    'status' => 'pending_pickup',
                    'pickup_status' => 'pending',
                    'delivery_status' => 'not_ready',
                    'cod_status' => $paymentType === 'cod' ? 'pending' : 'none',
                    'settlement_status' => $paymentType === 'cod' ? 'unsettled' : 'none',
                    'created_by' => $actorId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('shipment_charge_breakdowns')->insert([
                    'shipment_id' => $shipmentId,
                    'base_fee' => $fare['base_fee'],
                    'distance_km' => $fare['distance_km'],
                    'distance_fee' => $fare['distance_fee'],
                    'actual_weight' => $fare['actual_weight'],
                    'volumetric_weight' => $fare['volumetric_weight'],
                    'chargeable_weight' => $fare['chargeable_weight'],
                    'weight_fee' => $fare['weight_fee'],
                    'cod_fee' => $fare['cod_fee'],
                    'delivery_charge' => $fare['delivery_charge'],
                    'cod_amount' => $fare['cod_amount'],
                    'total_collectable' => $fare['total_collectable'],
                    'meta' => json_encode($quote),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->createRouteSteps($shipmentId, $quote['route'], $origin, $destination);
                $pickupId = $this->createPickupRequest($shipmentId, $merchantId, $payload, $origin);
                $this->logEvent($shipmentId, 'shipment.created', 'pending_pickup', $origin['branch_id'], $origin['sub_branch_id'], $actorId, 'Shipment created');

                if ($paymentType === 'cod') {
                    DB::table('cod_collections')->insert([
                        'shipment_id' => $shipmentId,
                        'merchant_id' => $merchantId,
                        'cod_amount' => $codAmount,
                        'delivery_charge_collected' => $fare['delivery_charge_paid_by'] === 'customer' ? $fare['delivery_charge'] : 0,
                        'total_collected' => 0,
                        'status' => 'pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                return $this->response($shipmentId, $quote, $pickupId);
            });

            if ($idempotencyKey) {
                DB::table('merchant_api_idempotency_keys')->updateOrInsert(
                    ['merchant_id' => $merchantId, 'idempotency_key' => $idempotencyKey],
                    [
                        'shipment_id' => data_get($result, 'shipment.id'),
                        'request_hash' => sha1(json_encode($payload)),
                        'response_payload' => json_encode($result),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }

            $this->afterCreate($result);

            return $result;
        });
    }

    public function showForMerchant(int $merchantId, string|int $shipment): array
    {
        $row = DB::table('shipments')
            ->where('merchant_id', $merchantId)
            ->where(function ($q) use ($shipment) {
                $q->where('id', $shipment)->orWhere('tracking_number', $shipment);
            })
            ->first();

        if (!$row) {
            throw ValidationException::withMessages(['shipment' => 'Shipment not found.']);
        }

        return $this->fullShipmentResponse((int) $row->id);
    }

    private function guardMerchant(int $merchantId): void
    {
        $merchant = DB::table('merchants')->where('id', $merchantId)->first();

        if (!$merchant) {
            throw ValidationException::withMessages(['merchant_id' => 'Merchant not found.']);
        }

        $status = strtolower((string) ($merchant->status ?? ''));
        $verification = strtolower((string) ($merchant->verification_status ?? ''));

        if (!in_array($status, ['active', 'approved'], true) && !in_array($verification, ['approved', 'verified'], true)) {
            throw ValidationException::withMessages(['merchant_id' => 'Merchant is not active or approved.']);
        }
    }

    private function createPickupRequest(int $shipmentId, int $merchantId, array $payload, array $origin): int
    {
        $location = null;
        $pickupLocationId = data_get($payload, 'pickup_location_id');

        if ($pickupLocationId && DB::getSchemaBuilder()->hasTable('merchant_pickup_locations')) {
            $location = DB::table('merchant_pickup_locations')->where('id', $pickupLocationId)->first();
        }

        return DB::table('pickup_requests')->insertGetId([
            'shipment_id' => $shipmentId,
            'merchant_id' => $merchantId,
            'pickup_location_id' => $pickupLocationId,
            'branch_id' => $origin['branch_id'],
            'sub_branch_id' => $origin['sub_branch_id'],
            'status' => 'pending',
            'pickup_address' => $location?->address ?: data_get($payload, 'pickup.address'),
            'contact_person' => $location?->contact_person ?: data_get($payload, 'pickup.contact_person'),
            'phone' => $location?->phone ?: data_get($payload, 'pickup.phone'),
            'parcel_count' => data_get($payload, 'package.pieces', data_get($payload, 'pieces', 1)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createRouteSteps(int $shipmentId, array $route, array $origin, array $destination): void
    {
        DB::table('shipment_route_steps')->insert([
            'shipment_id' => $shipmentId,
            'sequence' => 1,
            'type' => 'pickup_to_origin',
            'to_branch_id' => $origin['branch_id'],
            'to_sub_branch_id' => $origin['sub_branch_id'],
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($route['requires_transfer']) {
            DB::table('shipment_route_steps')->insert([
                'shipment_id' => $shipmentId,
                'sequence' => 2,
                'type' => 'branch_transfer',
                'from_branch_id' => $origin['branch_id'],
                'from_sub_branch_id' => $origin['sub_branch_id'],
                'to_branch_id' => $destination['branch_id'],
                'to_sub_branch_id' => $destination['sub_branch_id'],
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('shipment_route_steps')->insert([
            'shipment_id' => $shipmentId,
            'sequence' => $route['requires_transfer'] ? 3 : 2,
            'type' => 'destination_to_customer',
            'from_branch_id' => $destination['branch_id'],
            'from_sub_branch_id' => $destination['sub_branch_id'],
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function response(int $shipmentId, array $quote, int $pickupId): array
    {
        $shipment = DB::table('shipments')->where('id', $shipmentId)->first();

        return [
            'success' => true,
            'message' => 'Shipment created successfully.',
            'shipment' => $shipment,
            'pickup_id' => $pickupId,
            'quote' => $quote,
            'tracking_url' => url('/track/' . $shipment->tracking_number),
        ];
    }

    public function fullShipmentResponse(int $shipmentId): array
    {
        return [
            'shipment' => DB::table('shipments')->where('id', $shipmentId)->first(),
            'charge' => DB::table('shipment_charge_breakdowns')->where('shipment_id', $shipmentId)->first(),
            'pickup' => DB::table('pickup_requests')->where('shipment_id', $shipmentId)->first(),
            'delivery' => DB::table('delivery_assignments')->where('shipment_id', $shipmentId)->latest()->first(),
            'route_steps' => DB::table('shipment_route_steps')->where('shipment_id', $shipmentId)->orderBy('sequence')->get(),
            'events' => DB::table('shipment_lifecycle_events')->where('shipment_id', $shipmentId)->latest()->limit(50)->get(),
            'cod' => DB::table('cod_collections')->where('shipment_id', $shipmentId)->first(),
        ];
    }

    private function afterCreate(array $result): void
    {
        $shipment = data_get($result, 'shipment');

        Cache::forget('dashboard:admin');
        Cache::forget('dashboard:branch:' . ($shipment->origin_branch_id ?? ''));

        if (class_exists('App\\Events\\ShipmentStatusUpdated')) {
            event(new \App\Events\ShipmentStatusUpdated((object) (array) $shipment));
        }
    }

    public function logEvent(int $shipmentId, string $event, ?string $status = null, ?int $branchId = null, ?int $subBranchId = null, ?int $userId = null, ?string $remarks = null, ?array $meta = null): void
    {
        DB::table('shipment_lifecycle_events')->insert([
            'shipment_id' => $shipmentId,
            'event' => $event,
            'status' => $status,
            'branch_id' => $branchId,
            'sub_branch_id' => $subBranchId,
            'user_id' => $userId,
            'remarks' => $remarks,
            'meta' => $meta ? json_encode($meta) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
