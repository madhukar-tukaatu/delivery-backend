<?php

namespace Modules\Rate\Services\Pricing;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PricingRuleCalculator
{
    public function __construct(
        private readonly PricingSettingsResolverService $settingsResolver
    ) {
    }

    public function calculateDelivery(array $context): array
    {
        $branchTransferRouteId = isset(
            $context['branch_transfer_route_id']
        )
            ? (int) $context['branch_transfer_route_id']
            : null;

        if (
            $branchTransferRouteId !== null &&
            $branchTransferRouteId <= 0
        ) {
            $branchTransferRouteId = null;
        }

        $resolvedSettings = $this
            ->settingsResolver
            ->resolve($branchTransferRouteId);

        $settings = $resolvedSettings['settings'];

        $pickupBranchId = (int) (
            $context['pickup_branch_id'] ?? 0
        );

        $deliveryBranchId = (int) (
            $context['delivery_branch_id'] ?? -1
        );

        $sameBranch = $pickupBranchId === $deliveryBranchId;

        $baseRate = max(
            0,
            (float) ($context['base_rate'] ?? 0)
        );

        $parcelWeight = max(
            0,
            (float) ($context['parcel_weight'] ?? 0)
        );

        $deliveryDistance = max(
            0,
            (float) ($context['delivery_distance_km'] ?? 0)
        );

        $packetCount = max(
            1,
            (int) ($context['packet_count'] ?? 1)
        );

        $parcelType = strtolower(
            trim(
                (string) (
                    $context['parcel_type']
                    ?? 'non_fragile'
                )
            )
        );

        $serviceType = $this->normalizeServiceType(
            (string) (
                $context['service_type']
                ?? 'standard'
            )
        );

        $requestedAt = $context['requested_at'] ?? now();

        $baseWeightKg = max(
            0,
            (float) ($settings->base_weight_kg ?? 1.5)
        );

        $baseDistanceKm = max(
            0,
            (float) ($settings->base_distance_km ?? 5)
        );

        $weightRounding = strtolower(
            (string) ($settings->weight_rounding ?? 'none')
        );

        $distanceRounding = strtolower(
            (string) ($settings->distance_rounding ?? 'none')
        );

        $moneyRounding = strtolower(
            (string) ($settings->money_rounding ?? 'round')
        );

        $excessWeight = max(
            0,
            $parcelWeight - $baseWeightKg
        );

        $billableExcessWeight = $this->applyUnitRounding(
            $excessWeight,
            $weightRounding
        );

        $weightRate = $sameBranch
            ? max(
                0,
                (float) ($settings->local_extra_weight_rate ?? 20)
            )
            : max(
                0,
                (float) ($settings->transfer_extra_weight_rate ?? 30)
            );

        $weightCharge = $billableExcessWeight * $weightRate;
        $afterWeight = $baseRate + $weightCharge;

        $fragileEnabled = (bool) (
            $settings->fragile_enabled ?? true
        );

        $fragileApplied =
            $fragileEnabled &&
            $parcelType === 'fragile';

        $fragileMultiplier = $fragileApplied
            ? max(
                1,
                (float) ($settings->fragile_multiplier ?? 1.05)
            )
            : 1.0;

        $afterFragile = $afterWeight * $fragileMultiplier;
        $fragileCharge = $afterFragile - $afterWeight;

        $extraDistance = max(
            0,
            $deliveryDistance - $baseDistanceKm
        );

        $billableExtraDistance = $this->applyUnitRounding(
            $extraDistance,
            $distanceRounding
        );

        $extraDistanceRate = max(
            0,
            (float) ($settings->extra_distance_rate ?? 6)
        );

        $distanceCharge =
            $billableExtraDistance * $extraDistanceRate;

        $beforeSameDay = $afterFragile + $distanceCharge;

        $sameDayEnabled = (bool) (
            $settings->same_day_enabled ?? true
        );

        $sameDayRequested = in_array(
            $serviceType,
            ['same_day', 'sdd'],
            true
        );

        $sameDayApplied =
            $sameDayEnabled &&
            $sameDayRequested;

        $sameDayMultiplier = 1.0;

        if ($sameDayApplied) {
            $sameDayCutoff = (string) (
                $settings->same_day_cutoff_time
                ?? '12:00'
            );

            $this->assertBeforeSameDayCutoff(
                requestedAt: $requestedAt,
                cutoff: $sameDayCutoff
            );

            $sameDayMultiplier = $sameBranch
                ? max(
                    1,
                    (float) (
                        $settings->local_same_day_multiplier
                        ?? 1.5
                    )
                )
                : max(
                    1,
                    (float) (
                        $settings->transfer_same_day_multiplier
                        ?? 2
                    )
                );
        }

        $afterSameDay = $beforeSameDay * $sameDayMultiplier;
        $sameDayCharge = $afterSameDay - $beforeSameDay;

        // Express
        $expressEnabled = (bool) ($settings->express_enabled ?? true);
        $expressRequested = $serviceType === 'express';
        $expressApplied = $expressEnabled && $expressRequested;
        $expressMultiplier = 1.0;

        if ($expressApplied) {
            $expressMultiplier = $sameBranch
                ? max(1, (float) ($settings->local_express_multiplier ?? 1.2))
                : max(1, (float) ($settings->transfer_express_multiplier ?? 1.3));
        }

        $afterExpress = $beforeSameDay * $expressMultiplier;
        $expressCharge = $afterExpress - $beforeSameDay;

        // Use whichever service surcharge applies (express or same_day, not both)
        $serviceCharge = $sameDayApplied ? $afterSameDay : ($expressApplied ? $afterExpress : $beforeSameDay);

        $pickupChargeEnabled = (bool) (
            $settings->pickup_charge_enabled ?? true
        );

        $minimumFreePackets = max(
            1,
            (int) (
                $settings->minimum_free_pickup_packets
                ?? 3
            )
        );

        $smallPickupCharge = 0.0;

        if (
            $pickupChargeEnabled &&
            $packetCount < $minimumFreePackets
        ) {
            $smallPickupCharge = max(
                0,
                (float) ($settings->small_pickup_charge ?? 50)
            );
        }

        $subtotalBeforeVat =
            $serviceCharge + $smallPickupCharge;

        $vatEnabled = (bool) (
            $settings->vat_enabled ?? true
        );

        $vatPercentage = $vatEnabled
            ? max(
                0,
                (float) ($settings->vat_percentage ?? 13)
            )
            : 0.0;

        $vatInclusive = true;

        $vatAmount =
            $vatEnabled && $vatPercentage > 0
                ? $subtotalBeforeVat *
                    $vatPercentage /
                    (100 + $vatPercentage)
                : 0.0;

        $finalPrice = $this->applyMoneyRounding(
            $subtotalBeforeVat,
            $moneyRounding
        );

        return [
            'settings_id' => (int) $settings->id,
            'pricing_settings_id' => (int) $settings->id,
            'pricing_settings_source' =>
                $resolvedSettings['source'],
            'pricing_settings_fallback' => (bool) $resolvedSettings['is_fallback'],
            'branch_transfer_route_id' => $branchTransferRouteId,
            'same_branch' => $sameBranch,
            'base_rate' => $this->money($baseRate),
            'weight' => [
                'parcel_weight_kg' => round($parcelWeight, 3),
                'base_weight_kg' => round($baseWeightKg, 3),
                'excess_weight_kg' => round($excessWeight, 3),
                'billable_excess_weight_kg' =>
                    round($billableExcessWeight, 3),
                'rounding' => $weightRounding,
                'rate_type' => $sameBranch ? 'local' : 'transfer',
                'rate_per_kg' => $this->money($weightRate),
                'charge' => $this->money($weightCharge),
            ],
            'fragile' => [
                'enabled' => $fragileEnabled,
                'applied' => $fragileApplied,
                'multiplier' => round($fragileMultiplier, 4),
                'charge' => $this->money($fragileCharge),
            ],
            'distance' => [
                'delivery_distance_km' =>
                    round($deliveryDistance, 3),
                'base_distance_km' =>
                    round($baseDistanceKm, 3),
                'extra_distance_km' => round($extraDistance, 3),
                'billable_extra_distance_km' =>
                    round($billableExtraDistance, 3),
                'rounding' => $distanceRounding,
                'rate_per_km' => $this->money($extraDistanceRate),
                'charge' => $this->money($distanceCharge),
            ],
            'same_day' => [
                'enabled' => $sameDayEnabled,
                'requested' => $sameDayRequested,
                'applied' => $sameDayApplied,
                'multiplier' => round($sameDayMultiplier, 4),
                'charge' => $this->money($sameDayCharge),
                'cutoff_time' => (string) (
                    $settings->same_day_cutoff_time
                    ?? '12:00'
                ),
            ],
            'express' => [
                'enabled' => $expressEnabled,
                'requested' => $expressRequested,
                'applied' => $expressApplied,
                'multiplier' => round($expressMultiplier, 4),
                'charge' => $this->money($expressCharge),
            ],
            'pickup_minimum' => [
                'enabled' => $pickupChargeEnabled,
                'packet_count' => $packetCount,
                'minimum_free_pickup_packets' =>
                    $minimumFreePackets,
                'applied' => $smallPickupCharge > 0,
                'charge' => $this->money($smallPickupCharge),
            ],
            'vat' => [
                'enabled' => $vatEnabled,
                'percentage' => round($vatPercentage, 2),
                'inclusive' => $vatInclusive,
                'amount' => $this->money($vatAmount),
            ],
            'subtotal_before_vat' =>
                $this->money($subtotalBeforeVat),
            'money_rounding' => $moneyRounding,
            'final_price' => $this->money($finalPrice),
            'currency' => 'NPR',
        ];
    }

    public function calculateReturnCharge(
        string $scenarioCode,
        float $baseRate,
        float $distanceTravelledKm = 0
    ): array {
        $rule = DB::table('pricing_return_rules')
            ->where('scenario_code', $scenarioCode)
            ->where('is_active', true)
            ->first();

        if (!$rule) {
            throw ValidationException::withMessages([
                'scenario_code' => [
                    "No active return-pricing rule exists for {$scenarioCode}.",
                ],
            ]);
        }

        $baseRatePercentage = max(
            0,
            (float) $rule->base_rate_percentage
        );

        $distanceRate = max(
            0,
            (float) $rule->distance_rate_per_km
        );

        $fixedCharge = max(
            0,
            (float) $rule->fixed_charge
        );

        $baseComponent =
            max(0, $baseRate) *
            $baseRatePercentage /
            100;

        $distanceComponent =
            max(0, $distanceTravelledKm) *
            $distanceRate;

        $finalCharge =
            $baseComponent +
            $distanceComponent +
            $fixedCharge;

        return [
            'scenario_code' => $scenarioCode,
            'rule_id' => (int) $rule->id,
            'base_rate' => $this->money($baseRate),
            'base_rate_percentage' =>
                $this->money($baseRatePercentage),
            'distance_travelled_km' =>
                round(max(0, $distanceTravelledKm), 3),
            'distance_rate_per_km' =>
                $this->money($distanceRate),
            'base_component' =>
                $this->money($baseComponent),
            'distance_component' =>
                $this->money($distanceComponent),
            'fixed_charge' => $this->money($fixedCharge),
            'final_charge' => $this->money($finalCharge),
            'currency' => 'NPR',
        ];
    }

    private function assertBeforeSameDayCutoff(
        mixed $requestedAt,
        string $cutoff
    ): void {
        $date = $requestedAt instanceof CarbonInterface
            ? $requestedAt
            : Carbon::parse($requestedAt);

        [$hour, $minute] = array_pad(
            array_map(
                'intval',
                explode(':', $cutoff, 2)
            ),
            2,
            0
        );

        $cutoffAt = $date
            ->copy()
            ->setTime($hour, $minute);

        if ($date->greaterThan($cutoffAt)) {
            throw ValidationException::withMessages([
                'service_type' => [
                    "Same-day delivery is only available before {$cutoff}.",
                ],
            ]);
        }
    }

    private function normalizeServiceType(
        string $serviceType
    ): string {
        return strtolower(
            str_replace(
                ['-', ' '],
                '_',
                trim($serviceType)
            )
        );
    }

    private function applyUnitRounding(
        float $value,
        ?string $mode
    ): float {
        $mode = strtolower(
            trim((string) ($mode ?? 'none'))
        );

        return match ($mode) {
            'ceil' => ceil($value),
            'floor' => floor($value),
            'round' => round($value),
            default => $value,
        };
    }

    private function applyMoneyRounding(
        float $value,
        ?string $mode
    ): float {
        $mode = strtolower(
            trim((string) ($mode ?? 'round'))
        );

        return match ($mode) {
            'ceil' => ceil($value),
            'floor' => floor($value),
            'none' => $value,
            default => round($value, 2),
        };
    }

    private function money(float $value): float
    {
        return round($value, 2);
    }
}
