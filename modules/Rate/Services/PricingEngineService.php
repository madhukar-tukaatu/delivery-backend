<?php

namespace Modules\Rate\Services;

use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Branch\Models\Branch;
use Modules\Branch\Models\BranchPricingRule;
use Modules\Branch\Models\BranchTransferLane;
use Modules\Merchant\Models\MerchantApiKey;

class PricingEngineService
{
    public function calculate(array $data, MerchantApiKey $apiKey): array
    {
        $pickupLat = (float) $data['pickup_latitude'];
        $pickupLng = (float) $data['pickup_longitude'];
        $deliveryLat = (float) $data['delivery_latitude'];
        $deliveryLng = (float) $data['delivery_longitude'];

        $pickupBranch = $this->nearestBranch($pickupLat, $pickupLng);
        $deliveryBranch = $this->nearestBranch($deliveryLat, $deliveryLng);

        if (!$pickupBranch || !$deliveryBranch) {
            throw ValidationException::withMessages([
                'branch' => 'No active branch found for pricing.',
            ]);
        }

        $pickupRule = BranchPricingRule::query()
            ->where('branch_id', $pickupBranch->id)
            ->where('is_active', true)
            ->latest()
            ->first();

        $deliveryRule = BranchPricingRule::query()
            ->where('branch_id', $deliveryBranch->id)
            ->where('is_active', true)
            ->latest()
            ->first();

        if (!$pickupRule) {
            throw ValidationException::withMessages([
                'pickup_branch' => 'Pickup branch pricing rule is missing.',
            ]);
        }

        if (!$deliveryRule) {
            throw ValidationException::withMessages([
                'delivery_branch' => 'Delivery branch pricing rule is missing.',
            ]);
        }

        $pickupDistanceKm = $this->distanceKm(
            (float) $pickupBranch->latitude,
            (float) $pickupBranch->longitude,
            $pickupLat,
            $pickupLng
        );

        $deliveryDistanceKm = $this->distanceKm(
            (float) $deliveryBranch->latitude,
            (float) $deliveryBranch->longitude,
            $deliveryLat,
            $deliveryLng
        );

        if (
            $pickupRule->max_pickup_distance_km &&
            $pickupDistanceKm > (float) $pickupRule->max_pickup_distance_km
        ) {
            throw ValidationException::withMessages([
                'pickup_address' => 'Pickup address is outside supported pickup range.',
            ]);
        }

        if (
            $deliveryRule->max_delivery_distance_km &&
            $deliveryDistanceKm > (float) $deliveryRule->max_delivery_distance_km
        ) {
            throw ValidationException::withMessages([
                'delivery_address' => 'Delivery address is outside supported delivery range.',
            ]);
        }

        $pickupExtraKm = max(0, $pickupDistanceKm - (float) $pickupRule->base_radius_km);
        $deliveryExtraKm = max(0, $deliveryDistanceKm - (float) $deliveryRule->base_radius_km);

        $pickupBillableExtraKm = ceil($pickupExtraKm);
        $deliveryBillableExtraKm = ceil($deliveryExtraKm);

        $pickupExtraCharge = $pickupBillableExtraKm * (float) $pickupRule->pickup_extra_per_km;
        $deliveryExtraCharge = $deliveryBillableExtraKm * (float) $deliveryRule->delivery_extra_per_km;

        $parcelWeight = max(0, (float) ($data['parcel_weight'] ?? 0));

        $baseWeight = max(
            (float) $pickupRule->base_weight_kg,
            (float) $deliveryRule->base_weight_kg
        );

        $extraWeightKg = max(0, $parcelWeight - $baseWeight);
        $billableExtraWeightKg = ceil($extraWeightKg);

        $extraWeightRate = max(
            (float) $pickupRule->extra_weight_per_kg,
            (float) $deliveryRule->extra_weight_per_kg
        );

        $weightCharge = $billableExtraWeightKg * $extraWeightRate;

        $transferFee = 0;
        $estimatedHours = null;

        if ((int) $pickupBranch->id !== (int) $deliveryBranch->id) {
            $lane = BranchTransferLane::query()
                ->where('from_branch_id', $pickupBranch->id)
                ->where('to_branch_id', $deliveryBranch->id)
                ->where('is_active', true)
                ->first();

            if (!$lane) {
                throw ValidationException::withMessages([
                    'route' => 'No active branch transfer lane found for this route.',
                ]);
            }

            $transferFee =
                (float) $lane->base_transfer_fee +
                (ceil($parcelWeight) * (float) $lane->per_kg_fee);

            $estimatedHours = $lane->estimated_hours;
        }

        $paymentType = strtolower($data['payment_type'] ?? 'prepaid');
        $codAmount = max(0, (float) ($data['cod_amount'] ?? 0));

        $codFee = 0;

        if ($paymentType === 'cod') {
            $codFee =
                max((float) $pickupRule->cod_fee_fixed, (float) $deliveryRule->cod_fee_fixed)
                + (($codAmount * max(
                    (float) $pickupRule->cod_fee_percentage,
                    (float) $deliveryRule->cod_fee_percentage
                )) / 100);
        }

        $basePickupFee = (float) $pickupRule->base_pickup_fee;
        $baseDeliveryFee = (float) $deliveryRule->base_delivery_fee;

        $discount = 0;

        $finalPrice =
            $basePickupFee +
            $baseDeliveryFee +
            $transferFee +
            $pickupExtraCharge +
            $deliveryExtraCharge +
            $weightCharge +
            $codFee -
            $discount;

        $finalPrice = max(0, round($finalPrice, 2));

        return [
            'merchant_id' => $apiKey->merchant_id,
            'pickup_branch' => [
                'id' => $pickupBranch->id,
                'name' => $pickupBranch->name ?? 'Pickup Branch',
            ],
            'delivery_branch' => [
                'id' => $deliveryBranch->id,
                'name' => $deliveryBranch->name ?? 'Delivery Branch',
            ],
            'service_type' => $data['service_type'] ?? 'standard',
            'estimated_hours' => $estimatedHours,
            'currency' => 'NPR',
            'final_price' => $finalPrice,
            'breakdown' => [
                'base_pickup_fee' => round($basePickupFee, 2),
                'base_delivery_fee' => round($baseDeliveryFee, 2),
                'base_transfer_fee' => round($transferFee, 2),

                'pickup_distance_km' => round($pickupDistanceKm, 2),
                'pickup_base_radius_km' => round((float) $pickupRule->base_radius_km, 2),
                'pickup_extra_km' => round($pickupExtraKm, 2),
                'pickup_billable_extra_km' => $pickupBillableExtraKm,
                'pickup_extra_charge' => round($pickupExtraCharge, 2),

                'delivery_distance_km' => round($deliveryDistanceKm, 2),
                'delivery_base_radius_km' => round((float) $deliveryRule->base_radius_km, 2),
                'delivery_extra_km' => round($deliveryExtraKm, 2),
                'delivery_billable_extra_km' => $deliveryBillableExtraKm,
                'delivery_extra_charge' => round($deliveryExtraCharge, 2),

                'parcel_weight' => round($parcelWeight, 2),
                'base_weight_kg' => round($baseWeight, 2),
                'extra_weight_kg' => round($extraWeightKg, 2),
                'billable_extra_weight_kg' => $billableExtraWeightKg,
                'weight_charge' => round($weightCharge, 2),

                'payment_type' => $paymentType,
                'cod_amount' => round($codAmount, 2),
                'cod_fee' => round($codFee, 2),

                'discount' => round($discount, 2),
                'final_price' => $finalPrice,
            ],
        ];
    }

    private function nearestBranch(float $lat, float $lng): ?Branch
    {
        $query = Branch::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');

        if (Schema::hasColumn('branches', 'is_active')) {
            $query->where('is_active', true);
        }

        return $query
            ->get()
            ->map(function ($branch) use ($lat, $lng) {
                $branch->distance_km = $this->distanceKm(
                    (float) $branch->latitude,
                    (float) $branch->longitude,
                    $lat,
                    $lng
                );

                return $branch;
            })
            ->sortBy('distance_km')
            ->first();
    }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a =
            sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) *
            cos(deg2rad($lat2)) *
            sin($dLng / 2) *
            sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadiusKm * $c, 4);
    }
}