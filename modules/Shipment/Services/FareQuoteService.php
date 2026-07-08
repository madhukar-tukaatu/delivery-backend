<?php

namespace Modules\Shipment\Services;

use Modules\Merchant\Models\Merchant;

class FareQuoteService
{
    public function __construct(private BranchAssignmentService $branchAssignmentService)
    {
    }

    public function quote(Merchant $merchant, array $payload): array
    {
        $pickupLocation = $this->branchAssignmentService->resolveMerchantPickupLocation(
            $merchant,
            $payload['pickup_location_id'] ?? null
        );

        $origin = $this->branchAssignmentService->resolveOrigin($merchant, $pickupLocation);
        $destination = $this->branchAssignmentService->resolveDestination($payload['delivery'] ?? []);
        $route = $this->branchAssignmentService->buildRoute($origin, $destination);

        $pickupCoords = $this->branchAssignmentService->pickupCoordinates($pickupLocation, $merchant);
        $delivery = $payload['delivery'] ?? [];
        $distanceKm = $this->branchAssignmentService->distanceKm(
            $pickupCoords['lat'] ? (float) $pickupCoords['lat'] : null,
            $pickupCoords['lng'] ? (float) $pickupCoords['lng'] : null,
            isset($delivery['latitude']) ? (float) $delivery['latitude'] : null,
            isset($delivery['longitude']) ? (float) $delivery['longitude'] : null
        );

        $package = $payload['package'] ?? [];
        $payment = $payload['payment'] ?? [];

        $actualWeight = (float) ($package['weight'] ?? 0);
        $length = (float) ($package['length_cm'] ?? 0);
        $width = (float) ($package['width_cm'] ?? 0);
        $height = (float) ($package['height_cm'] ?? 0);
        $volumetric = $length && $width && $height
            ? round(($length * $width * $height) / config('delivery_operations.pricing.volumetric_divisor', 5000), 2)
            : 0;

        $chargeableWeight = max($actualWeight, $volumetric, 0.1);

        $baseFee = (float) config('delivery_operations.pricing.base_fee', 80);
        $distanceFee = round($distanceKm * (float) config('delivery_operations.pricing.rate_per_km', 12), 2);
        $weightFee = round($chargeableWeight * (float) config('delivery_operations.pricing.rate_per_kg', 25), 2);

        $codAmount = (float) ($payment['cod_amount'] ?? 0);
        $paymentType = $payment['type'] ?? 'prepaid';
        $codFee = $paymentType === 'cod'
            ? round((float) config('delivery_operations.pricing.cod_fee_fixed', 20) + ($codAmount * ((float) config('delivery_operations.pricing.cod_fee_percent', 0) / 100)), 2)
            : 0;

        $deliveryCharge = round($baseFee + $distanceFee + $weightFee + $codFee, 2);
        $paidBy = $payment['delivery_charge_paid_by'] ?? 'merchant';
        $totalCollectable = $paymentType === 'cod'
            ? $codAmount + ($paidBy === 'customer' ? $deliveryCharge : 0)
            : 0;

        return [
            'pickup_location' => $pickupLocation,
            'origin' => $origin,
            'destination' => $destination,
            'route' => $route,
            'fare' => [
                'base_fee' => $baseFee,
                'distance_km' => $distanceKm,
                'distance_fee' => $distanceFee,
                'actual_weight' => $actualWeight,
                'volumetric_weight' => $volumetric,
                'chargeable_weight' => $chargeableWeight,
                'weight_fee' => $weightFee,
                'cod_fee' => $codFee,
                'delivery_charge' => $deliveryCharge,
                'total_collectable' => round($totalCollectable, 2),
            ],
        ];
    }
}
