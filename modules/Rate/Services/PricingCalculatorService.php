<?php

namespace Modules\Rate\Services;

use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Branch\Models\Branch;
use Modules\Rate\Models\PricingSetting;

class PricingCalculatorService
{
    public function __construct(
        private readonly BranchRouteRateService $routeRateService
    ) {
    }

    public function calculate(array $input): array
    {
        $setting = PricingSetting::current();

        if (!$setting) {
            throw ValidationException::withMessages([
                'pricing' => [
                    'No active pricing configuration was found.',
                ],
            ]);
        }

        $originBranchId = (int) $input['origin_branch_id'];
        $destinationBranchId = (int) $input['destination_branch_id'];

        $originBranch = Branch::query()
            ->findOrFail($originBranchId);

        $destinationBranch = Branch::query()
            ->findOrFail($destinationBranchId);

        $routeRate = $this->routeRateService->resolve(
            $originBranchId,
            $destinationBranchId
        );

        $weightKg = max(
            (float) $input['weight_kg'],
            0.01
        );

        $distanceKm = max(
            (float) $input['distance_km'],
            0
        );

        $packetCount = max(
            (int) ($input['packet_count'] ?? 1),
            1
        );

        $isFragile = filter_var(
            $input['is_fragile'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        $isSameDay = filter_var(
            $input['is_same_day'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        $isBranchTransfer =
            $originBranchId !== $destinationBranchId;

        $includedWeightKg = $routeRate->included_weight_kg !== null
            ? (float) $routeRate->included_weight_kg
            : (float) $setting->base_weight_kg;

        $includedDistanceKm = $routeRate->included_distance_km !== null
            ? (float) $routeRate->included_distance_km
            : (float) $setting->base_distance_km;

        $weightRate = $routeRate->extra_weight_rate !== null
            ? (float) $routeRate->extra_weight_rate
            : (
                $isBranchTransfer
                    ? (float) $setting->transfer_extra_weight_rate
                    : (float) $setting->local_extra_weight_rate
            );

        $distanceRate = $routeRate->extra_distance_rate !== null
            ? (float) $routeRate->extra_distance_rate
            : (float) $setting->extra_distance_rate;

        $baseRate = (float) $routeRate->base_rate;

        $rawExcessWeight = max(
            $weightKg - $includedWeightKg,
            0
        );

        $rawExcessDistance = max(
            $distanceKm - $includedDistanceKm,
            0
        );

        $chargeableWeight = $this->roundUnit(
            $rawExcessWeight,
            $setting->weight_rounding
        );

        $chargeableDistance = $this->roundUnit(
            $rawExcessDistance,
            $setting->distance_rounding
        );

        $weightCharge = $chargeableWeight * $weightRate;
        $distanceCharge = $chargeableDistance * $distanceRate;

        $baseSubtotal =
            $baseRate +
            $weightCharge +
            $distanceCharge;

        $fragileMultiplier = 1.0;
        $fragileCharge = 0.0;

        if (
            $isFragile &&
            $setting->fragile_enabled
        ) {
            $fragileMultiplier =
                (float) $setting->fragile_multiplier;

            $fragileCharge =
                $baseSubtotal *
                max($fragileMultiplier - 1, 0);
        }

        $afterFragile =
            $baseSubtotal +
            $fragileCharge;

        $sameDayMultiplier = 1.0;
        $sameDayCharge = 0.0;

        if (
            $isSameDay &&
            $setting->same_day_enabled
        ) {
            $this->validateSameDayAvailability(
                $input['requested_at'] ?? null,
                $setting->same_day_cutoff_time
            );

            $sameDayMultiplier =
                $routeRate->same_day_multiplier !== null
                    ? (float) $routeRate->same_day_multiplier
                    : (
                        $isBranchTransfer
                            ? (float) $setting
                                ->transfer_same_day_multiplier
                            : (float) $setting
                                ->local_same_day_multiplier
                    );

            $sameDayCharge =
                $afterFragile *
                max($sameDayMultiplier - 1, 0);
        }

        $afterSameDay =
            $afterFragile +
            $sameDayCharge;

        $pickupCharge = 0.0;

        if (
            $setting->pickup_charge_enabled &&
            $packetCount <
                (int) $setting
                    ->minimum_free_pickup_packets
        ) {
            $pickupCharge =
                (float) $setting->small_pickup_charge;
        }

        $subtotal =
            $afterSameDay +
            $pickupCharge;

        $vatPercentage =
            $setting->vat_enabled
                ? (float) $setting->vat_percentage
                : 0.0;

        $vatAmount = 0.0;
        $totalAmount = $subtotal;

        if ($vatPercentage > 0) {
            if ($setting->vat_inclusive) {
                $vatAmount =
                    $subtotal -
                    (
                        $subtotal /
                        (1 + ($vatPercentage / 100))
                    );
            } else {
                $vatAmount =
                    $subtotal *
                    ($vatPercentage / 100);

                $totalAmount =
                    $subtotal +
                    $vatAmount;
            }
        }

        return [
            'setting_id' => $setting->id,
            'branch_route_rate_id' => $routeRate->id,

            'origin_branch' => [
                'id' => $originBranch->id,
                'name' => $originBranch->name,
                'code' => $originBranch->code,
            ],

            'destination_branch' => [
                'id' => $destinationBranch->id,
                'name' => $destinationBranch->name,
                'code' => $destinationBranch->code,
            ],

            'shipment' => [
                'weight_kg' => round($weightKg, 2),
                'distance_km' => round($distanceKm, 2),
                'packet_count' => $packetCount,
                'is_fragile' => $isFragile,
                'is_same_day' => $isSameDay,
                'is_branch_transfer' => $isBranchTransfer,
            ],

            'breakdown' => [
                'base_rate' => $this->money(
                    $baseRate,
                    $setting->money_rounding
                ),

                'included_weight_kg' =>
                    round($includedWeightKg, 2),

                'excess_weight_kg' =>
                    round($rawExcessWeight, 2),

                'chargeable_weight_kg' =>
                    round($chargeableWeight, 2),

                'weight_rate' => $this->money(
                    $weightRate,
                    $setting->money_rounding
                ),

                'weight_charge' => $this->money(
                    $weightCharge,
                    $setting->money_rounding
                ),

                'included_distance_km' =>
                    round($includedDistanceKm, 2),

                'excess_distance_km' =>
                    round($rawExcessDistance, 2),

                'chargeable_distance_km' =>
                    round($chargeableDistance, 2),

                'distance_rate' => $this->money(
                    $distanceRate,
                    $setting->money_rounding
                ),

                'distance_charge' => $this->money(
                    $distanceCharge,
                    $setting->money_rounding
                ),

                'fragile_multiplier' =>
                    round($fragileMultiplier, 4),

                'fragile_charge' => $this->money(
                    $fragileCharge,
                    $setting->money_rounding
                ),

                'same_day_multiplier' =>
                    round($sameDayMultiplier, 4),

                'same_day_charge' => $this->money(
                    $sameDayCharge,
                    $setting->money_rounding
                ),

                'pickup_charge' => $this->money(
                    $pickupCharge,
                    $setting->money_rounding
                ),

                'subtotal' => $this->money(
                    $subtotal,
                    $setting->money_rounding
                ),

                'vat_percentage' =>
                    round($vatPercentage, 2),

                'vat_inclusive' =>
                    (bool) $setting->vat_inclusive,

                'vat_amount' => $this->money(
                    $vatAmount,
                    $setting->money_rounding
                ),

                'total_amount' => $this->money(
                    $totalAmount,
                    $setting->money_rounding
                ),

                'currency' => 'NPR',
            ],
        ];
    }

    private function roundUnit(
        float $value,
        string $method
    ): float {
        return match ($method) {
            'exact' => round($value, 2),
            'floor' => floor($value),
            'round' => round($value),
            default => ceil($value),
        };
    }

    private function money(
        float $value,
        string $method
    ): float {
        return match ($method) {
            'ceil' => ceil($value),
            'floor' => floor($value),
            'round' => round($value),
            default => round($value, 2),
        };
    }

    private function validateSameDayAvailability(
        ?string $requestedAt,
        ?string $cutoffTime
    ): void {
        if (!$cutoffTime) {
            return;
        }

        $requested = $requestedAt
            ? Carbon::parse($requestedAt)
            : now();

        $cutoff = Carbon::parse(
            $requested->toDateString()
                . ' '
                . $cutoffTime
        );

        if ($requested->greaterThan($cutoff)) {
            throw ValidationException::withMessages([
                'is_same_day' => [
                    'Same-day delivery is unavailable after the cutoff time.',
                ],
            ]);
        }
    }
}