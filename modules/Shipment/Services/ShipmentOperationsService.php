<?php

namespace Modules\Shipment\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantPickupLocation;
use Modules\Shipment\Models\Shipment;

class ShipmentOperationsService
{
    public function __construct(
        private FareQuoteService $fareQuoteService,
        private TrackingNumberService $trackingNumberService,
        private PickupWorkflowService $pickupWorkflowService,
    ) {}

    public function quote(Merchant $merchant, array $payload): array
    {
        $this->guardMerchant($merchant);

        $payload = $this->normalizePayload($payload);

        $pickupLocation = $this->resolveMerchantPickupLocation($merchant, $payload);

        if ($pickupLocation) {
            $payload['pickup_location_id'] = $pickupLocation->id;
        }

        $quote = $this->fareQuoteService->quote($merchant, $payload);

        if (!isset($quote['pickup_location'])) {
            $quote['pickup_location'] = $pickupLocation;
        }

        return $this->formatQuote($quote);
    }

    public function create(Merchant $merchant, array $payload, string $source, $actor = null): array
    {
        $this->guardMerchant($merchant);

        $payload = $this->normalizePayload($payload);

        $pickupLocation = $this->resolveMerchantPickupLocation($merchant, $payload);

        if ($pickupLocation) {
            $payload['pickup_location_id'] = $pickupLocation->id;
        }

        $orderReference = $payload['order_reference'] ?? $payload['merchant_order_id'] ?? null;

        $lockKey = 'shipment:create:' . $merchant->id . ':' . ($orderReference ?: md5(json_encode($payload)));

        return Cache::lock($lockKey, 10)->block(5, function () use (
            $merchant,
            $payload,
            $source,
            $actor,
            $orderReference,
            $pickupLocation
        ) {
            if ($orderReference) {
                $existing = Shipment::where('merchant_id', $merchant->id)
                    ->where('order_reference', $orderReference)
                    ->first();

                if ($existing) {
                    return $this->showPayload($existing);
                }
            }

            return DB::transaction(function () use ($merchant, $payload, $source, $actor, $pickupLocation) {
                $quote = $this->fareQuoteService->quote($merchant, $payload);

                $fare = $quote['fare'];
                $route = $quote['route'];

                $resolvedPickupLocation = $quote['pickup_location'] ?? $pickupLocation;

                $delivery = $payload['delivery'];
                $customer = $payload['customer'];
                $package = $payload['package'];
                $payment = $payload['payment'] ?? [];

                $paymentType = $payment['type'] ?? 'prepaid';

                $shipment = new Shipment();
                $shipment->merchant_id = $merchant->id;
                $shipment->pickup_location_id = $resolvedPickupLocation?->id;
                $shipment->source = $source;
                $shipment->tracking_number = $this->trackingNumberService->generate();
                $shipment->order_reference = $payload['order_reference'] ?? $payload['merchant_order_id'] ?? null;
                $shipment->status = 'pending_pickup';

                $shipment->customer_name = $customer['name'];
                $shipment->customer_phone = $customer['phone'];
                $shipment->customer_email = $customer['email'] ?? null;

                $shipment->delivery_address = $delivery['address'];
                $shipment->delivery_city = $delivery['city'];
                $shipment->delivery_area = $delivery['area'] ?? null;
                $shipment->delivery_latitude = $delivery['latitude'] ?? null;
                $shipment->delivery_longitude = $delivery['longitude'] ?? null;

                $shipment->package_type = $package['type'] ?? 'parcel';
                $shipment->package_description = $package['description'] ?? null;
                $shipment->weight = $package['weight'];
                $shipment->length_cm = $package['length_cm'] ?? 0;
                $shipment->width_cm = $package['width_cm'] ?? 0;
                $shipment->height_cm = $package['height_cm'] ?? 0;
                $shipment->pieces = $package['pieces'] ?? 1;

                $shipment->payment_type = $paymentType;
                $shipment->pod_amount = $paymentType === 'pod'
                    ? (float) ($payment['pod_amount'] ?? 0)
                    : 0;

                $shipment->delivery_charge_paid_by = $payment['delivery_charge_paid_by'] ?? 'merchant';
                $shipment->delivery_charge = $fare['delivery_charge'] ?? 0;
                $shipment->total_collectable = $fare['total_collectable'] ?? 0;

                $shipment->origin_branch_id = $route['origin_branch_id'] ?? null;
                $shipment->origin_sub_branch_id = $route['origin_sub_branch_id'] ?? null;
                $shipment->destination_branch_id = $route['destination_branch_id'] ?? null;
                $shipment->destination_sub_branch_id = $route['destination_sub_branch_id'] ?? null;

                $shipment->current_branch_id = $route['origin_branch_id'] ?? null;
                $shipment->current_sub_branch_id = $route['origin_sub_branch_id'] ?? null;

                $shipment->requires_transfer = (bool) ($route['requires_transfer'] ?? false);
                $shipment->special_instruction = $payload['special_instruction'] ?? null;

                $shipment->save();

                DB::table('shipment_charges')->insert(array_merge($fare, [
                    'shipment_id' => $shipment->id,
                    'breakdown' => json_encode($fare),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));

                if ($shipment->payment_type === 'pod') {
                    DB::table('pod_transactions')->insert([
                        'shipment_id' => $shipment->id,
                        'merchant_id' => $merchant->id,
                        'pod_amount' => $shipment->pod_amount,
                        'delivery_charge' => $shipment->delivery_charge,
                        'total_collected' => $shipment->total_collectable,
                        'status' => 'pending_collection',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('shipment_tracking_events')->insert([
                    'shipment_id' => $shipment->id,
                    'actor_id' => $actor?->id,
                    'status' => 'pending_pickup',
                    'title' => 'Shipment created',
                    'description' => 'Shipment created and pickup request generated.',
                    'branch_id' => $shipment->origin_branch_id,
                    'sub_branch_id' => $shipment->origin_sub_branch_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $pickup = null;

                if (!$payload['self_drop']) {
                    $pickup = $this->pickupWorkflowService->createForShipment($shipment->fresh());
                }

                return $this->showPayload($shipment->fresh(), $pickup);
            });
        });
    }

    public function showPayload(Shipment $shipment, ?object $pickup = null): array
    {
        $pickup = $pickup ?: DB::table('pickup_requests')
            ->where('shipment_id', $shipment->id)
            ->latest('id')
            ->first();

        $charge = DB::table('shipment_charges')
            ->where('shipment_id', $shipment->id)
            ->latest('id')
            ->first();

        $pod = DB::table('pod_transactions')
            ->where('shipment_id', $shipment->id)
            ->first();

        $tracking = DB::table('shipment_tracking_events')
            ->where('shipment_id', $shipment->id)
            ->orderBy('id')
            ->get();

        $delivery = DB::table('delivery_assignments')
            ->where('shipment_id', $shipment->id)
            ->latest('id')
            ->first();

        return [
            'shipment' => $shipment,
            'pickup' => $pickup,
            'charge' => $charge,
            'pod' => $pod,
            'delivery' => $delivery,
            'tracking' => $tracking,
            'route' => [
                'origin_branch_id' => $shipment->origin_branch_id,
                'origin_sub_branch_id' => $shipment->origin_sub_branch_id,
                'destination_branch_id' => $shipment->destination_branch_id,
                'destination_sub_branch_id' => $shipment->destination_sub_branch_id,
                'requires_transfer' => (bool) $shipment->requires_transfer,
            ],
        ];
    }

    private function guardMerchant(Merchant $merchant): void
    {
        abort_unless(
            in_array($merchant->status, ['active', 'approved'], true),
            422,
            'Merchant is not active.'
        );
    }

    private function formatQuote(array $quote): array
    {
        $pickup = $quote['pickup_location'] ?? null;
        $origin = $quote['origin'] ?? [];
        $destination = $quote['destination'] ?? [];

        return [
            'pickup_location' => $pickup,
            'fare' => $quote['fare'] ?? [],
            'route' => array_merge($quote['route'] ?? [], [
                'origin_branch' => $origin['branch'] ?? null,
                'origin_sub_branch' => $origin['sub_branch'] ?? null,
                'destination_branch' => $destination['branch'] ?? null,
                'destination_sub_branch' => $destination['sub_branch'] ?? null,
            ]),
        ];
    }

    private function resolveMerchantPickupLocation(Merchant $merchant, array $payload): ?MerchantPickupLocation
    {
        if (!empty($payload['self_drop'])) {
            return null;
        }

        $pickupLocationId = $payload['pickup_location_id'] ?? null;

        $query = MerchantPickupLocation::query()
            ->where('merchant_id', $merchant->id)
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhereIn('status', ['active', 'approved']);
            });

        if ($pickupLocationId) {
            $pickupLocation = (clone $query)
                ->where('id', $pickupLocationId)
                ->first();

            if (!$pickupLocation) {
                throw ValidationException::withMessages([
                    'pickup_location_id' => 'Selected pickup location is invalid for this merchant.',
                ]);
            }

            return $pickupLocation;
        }

        $pickupLocation = (clone $query)
            ->orderByDesc('is_default')
            ->orderByRaw('CASE WHEN latitude IS NOT NULL AND longitude IS NOT NULL THEN 0 ELSE 1 END')
            ->orderBy('id')
            ->first();

        if (!$pickupLocation) {
            throw ValidationException::withMessages([
                'pickup_location_id' => 'No active pickup location found for this merchant. Please complete onboarding pickup location first.',
            ]);
        }

        return $pickupLocation;
    }

    private function normalizePayload(array $payload): array
    {
        $paymentType = $payload['payment_type']
            ?? data_get($payload, 'payment.type')
            ?? 'prepaid';

        $orderReference = $payload['merchant_order_id']
            ?? $payload['order_reference']
            ?? null;

        $deliveryAddress = $payload['customer_address']
            ?? $payload['delivery_address']
            ?? data_get($payload, 'delivery.address');

        $deliveryCity = $payload['customer_city']
            ?? $payload['delivery_city']
            ?? data_get($payload, 'delivery.city');

        $deliveryArea = $payload['customer_area']
            ?? $payload['delivery_area']
            ?? data_get($payload, 'delivery.area');

        $deliveryLat = $payload['delivery_lat']
            ?? $payload['delivery_latitude']
            ?? data_get($payload, 'delivery.latitude');

        $deliveryLng = $payload['delivery_lng']
            ?? $payload['delivery_longitude']
            ?? data_get($payload, 'delivery.longitude');

        return array_merge($payload, [
            'self_drop' => (bool) ($payload['self_drop'] ?? false),
            'pickup_location_id' => $payload['pickup_location_id'] ?? null,

            'merchant_order_id' => $orderReference,
            'order_reference' => $orderReference,

            'customer' => [
                'name' => $payload['customer_name']
                    ?? data_get($payload, 'customer.name'),

                'phone' => $payload['customer_phone']
                    ?? data_get($payload, 'customer.phone'),

                'email' => $payload['customer_email']
                    ?? data_get($payload, 'customer.email'),
            ],

            'delivery' => [
                'address' => $deliveryAddress,
                'city' => $deliveryCity,
                'area' => $deliveryArea,
                'latitude' => $deliveryLat,
                'longitude' => $deliveryLng,
            ],

            'package' => [
                'type' => $payload['package_type']
                    ?? data_get($payload, 'package.type')
                    ?? 'parcel',

                'description' => $payload['package_description']
                    ?? data_get($payload, 'package.description'),

                'weight' => $payload['weight']
                    ?? data_get($payload, 'package.weight'),

                'length_cm' => $payload['length_cm']
                    ?? data_get($payload, 'package.length_cm')
                    ?? 0,

                'width_cm' => $payload['width_cm']
                    ?? data_get($payload, 'package.width_cm')
                    ?? 0,

                'height_cm' => $payload['height_cm']
                    ?? data_get($payload, 'package.height_cm')
                    ?? 0,

                'pieces' => $payload['pieces']
                    ?? data_get($payload, 'package.pieces')
                    ?? 1,

                'value' => $payload['declared_value']
                    ?? data_get($payload, 'package.value')
                    ?? 0,
            ],

            'payment' => [
                'type' => $paymentType,

                'pod_amount' => $payload['pod_amount']
                    ?? data_get($payload, 'payment.pod_amount')
                    ?? 0,

                'delivery_charge_paid_by' => $payload['delivery_charge_paid_by']
                    ?? data_get($payload, 'payment.delivery_charge_paid_by')
                    ?? 'merchant',
            ],
        ]);
    }
}