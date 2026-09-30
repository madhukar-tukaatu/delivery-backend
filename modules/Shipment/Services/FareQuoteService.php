<?php

namespace Modules\Shipment\Services;

use Modules\Merchant\Models\Merchant;

/**
 * Quote delivery fare for merchant/store create flows.
 *
 * Uses CheckoutDeliveryChargeResolver (PricingEngine final_price, else explicit
 * payload charge). Does NOT fall back to legacy config base_fee (80).
 */
class FareQuoteService
{
    public function __construct(
        private BranchAssignmentService $branchAssignmentService,
        private CheckoutDeliveryChargeResolver $checkoutDeliveryChargeResolver,
    ) {
    }

    public function quote(Merchant $merchant, array $payload): array
    {
        $pickupLocation = $this->branchAssignmentService->resolveMerchantPickupLocation(
            $merchant,
            isset($payload['pickup_location_id']) ? (int) $payload['pickup_location_id'] : null
        );

        $origin = $this->branchAssignmentService->resolveOrigin($merchant, $pickupLocation);
        $destination = $this->branchAssignmentService->resolveDestination($payload['delivery'] ?? $payload);
        $route = method_exists($this->branchAssignmentService, 'buildRoute')
            ? $this->branchAssignmentService->buildRoute($origin, $destination)
            : [
                'origin_branch_id' => $origin['branch_id'] ?? null,
                'origin_sub_branch_id' => $origin['sub_branch_id'] ?? null,
                'destination_branch_id' => $destination['branch_id'] ?? null,
                'destination_sub_branch_id' => $destination['sub_branch_id'] ?? null,
                'requires_transfer' => ($origin['branch_id'] ?? null) !== ($destination['branch_id'] ?? null),
            ];

        $pickupCoords = $this->branchAssignmentService->pickupCoordinates($pickupLocation, $merchant);
        $delivery = $payload['delivery'] ?? [];

        $package = $payload['package'] ?? [];
        $payment = $payload['payment'] ?? [];

        $actualWeight = (float) (
            $package['weight']
            ?? $payload['weight']
            ?? $payload['package_weight']
            ?? $payload['parcel_weight']
            ?? 0
        );
        $length = (float) ($package['length_cm'] ?? $payload['length_cm'] ?? 0);
        $width = (float) ($package['width_cm'] ?? $payload['width_cm'] ?? 0);
        $height = (float) ($package['height_cm'] ?? $payload['height_cm'] ?? 0);
        $volumetric = $length && $width && $height
            ? round(($length * $width * $height) / (float) config('delivery_workflow.volumetric_divisor', 5000), 2)
            : 0.0;
        $chargeableWeight = max($actualWeight, $volumetric, 0.1);

        $paymentType = strtolower((string) (
            $payment['type']
            ?? $payload['payment_type']
            ?? 'prepaid'
        ));
        $podAmount = $paymentType === 'pod'
            ? round((float) ($payment['pod_amount'] ?? $payload['pod_amount'] ?? 0), 2)
            : 0.0;
        $paidBy = strtolower((string) (
            $payment['delivery_charge_paid_by']
            ?? $payload['delivery_charge_paid_by']
            ?? 'merchant'
        ));

        $resolvePayload = array_merge($payload, [
            'merchant_id' => $merchant->id,
            'pickup_lat' => $pickupCoords['lat'] ?? $payload['pickup_lat'] ?? null,
            'pickup_lng' => $pickupCoords['lng'] ?? $payload['pickup_lng'] ?? null,
            'delivery_lat' => $delivery['latitude']
                ?? $payload['delivery_lat']
                ?? $payload['delivery_latitude']
                ?? null,
            'delivery_lng' => $delivery['longitude']
                ?? $payload['delivery_lng']
                ?? $payload['delivery_longitude']
                ?? null,
            'weight' => $chargeableWeight,
            'parcel_weight' => $chargeableWeight,
            'service_type' => $payload['service_type'] ?? data_get($payload, 'service_type.code') ?? 'standard',
            'payment_type' => $paymentType,
            'pod_amount' => $podAmount,
            'delivery_charge_paid_by' => $paidBy,
            'delivery_charge' => $payload['delivery_charge'] ?? $payment['delivery_charge'] ?? null,
        ]);

        $priced = $this->checkoutDeliveryChargeResolver->resolveFromPayload($resolvePayload, [
            'lat' => isset($pickupCoords['lat']) ? (float) $pickupCoords['lat'] : null,
            'lng' => isset($pickupCoords['lng']) ? (float) $pickupCoords['lng'] : null,
        ]);

        $deliveryCharge = round((float) $priced['delivery_charge'], 2);
        $podFee = round((float) ($priced['pod_charge'] ?? 0), 2);
        $distanceKm = 0.0;
        if (method_exists($this->branchAssignmentService, 'distanceKm')) {
            $distanceKm = (float) $this->branchAssignmentService->distanceKm(
                $pickupCoords['lat'] ? (float) $pickupCoords['lat'] : null,
                $pickupCoords['lng'] ? (float) $pickupCoords['lng'] : null,
                isset($resolvePayload['delivery_lat']) ? (float) $resolvePayload['delivery_lat'] : null,
                isset($resolvePayload['delivery_lng']) ? (float) $resolvePayload['delivery_lng'] : null
            );
        }

        $totalCollectable = $paymentType === 'pod'
            ? $podAmount + ($paidBy === 'customer' ? $deliveryCharge : 0)
            : ($paidBy === 'customer' ? $deliveryCharge : 0);

        $breakdown = is_array($priced['breakdown'] ?? null) ? $priced['breakdown'] : [];
        $baseFee = round((float) (
            $breakdown['base_rate']
            ?? data_get($breakdown, 'breakdown.base_rate')
            ?? $deliveryCharge
        ), 2);

        return [
            'pickup_location' => $pickupLocation,
            'origin' => $origin,
            'destination' => $destination,
            'route' => $route,
            'fare' => [
                'base_fee' => $baseFee,
                'distance_km' => round($distanceKm, 2),
                'distance_fee' => round((float) (data_get($breakdown, 'distance_fee') ?? 0), 2),
                'actual_weight' => $actualWeight,
                'volumetric_weight' => $volumetric,
                'chargeable_weight' => $chargeableWeight,
                'weight_fee' => round((float) (data_get($breakdown, 'weight_fee') ?? 0), 2),
                'pod_fee' => $podFee,
                'pod_amount' => $podAmount,
                'pod_charge' => $podFee,
                'delivery_charge' => $deliveryCharge,
                'delivery_charge_paid_by' => $paidBy,
                'total_collectable' => round($totalCollectable, 2),
                'pricing_source' => $priced['source'] ?? null,
                'breakdown' => $priced['breakdown'] ?? null,
            ],
            // Flat aliases for FE / lifecycle callers that read top-level charge fields.
            'delivery_charge' => $deliveryCharge,
            'final_delivery_fee' => round($deliveryCharge + $podFee, 2),
            'pod_charge' => $podFee,
            'pricing_source' => $priced['source'] ?? null,
        ];
    }

    /**
     * Lifecycle-compatible quote: returns the fare array only (legacy call shape).
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $origin
     * @param  array<string, mixed>  $destination
     * @return array<string, mixed>
     */
    public function quoteForLifecycle(Merchant $merchant, array $payload, array $origin = [], array $destination = []): array
    {
        $full = $this->quote($merchant, $payload);
        $fare = $full['fare'];

        // Keep origin/destination from caller when already resolved.
        if ($origin !== []) {
            $fare['origin'] = $origin;
        }
        if ($destination !== []) {
            $fare['destination'] = $destination;
        }

        return $fare;
    }
}
