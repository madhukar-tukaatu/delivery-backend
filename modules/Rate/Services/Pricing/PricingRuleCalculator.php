<?php

namespace Modules\Rate\Services\Pricing;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PricingRuleCalculator
{
    public function __construct(
        private readonly ActivePricingSettingsService $settingsService
    ) {
    }

    /**
     * Apply the active pricing rules to a resolved route base rate.
     *
     * Expected context keys:
     * pickup_branch_id, delivery_branch_id, base_rate, parcel_weight,
     * delivery_distance_km, packet_count, parcel_type, service_type,
     * requested_at.
     */
    public function calculateDelivery(array $context): array
    {
        $settings = $this->settingsService->active();

        $sameBranch =
            (int) ($context['pickup_branch_id'] ?? 0) ===
            (int) ($context['delivery_branch_id'] ?? -1);

        $baseRate = max(0, (float) ($context['base_rate'] ?? 0));
        $parcelWeight = max(0, (float) ($context['parcel_weight'] ?? 0));
        $deliveryDistance = max(
            0,
            (float) ($context['delivery_distance_km'] ?? 0)
        );
        $packetCount = max(1, (int) ($context['packet_count'] ?? 1));
        $serviceType = strtolower(trim((string) ($context['service_type'] ?? 'standard')));
        $parcelType = strtolower(trim((string) ($context['parcel_type'] ?? 'non_fragile')));
        $requestedAt = $context['requested_at'] ?? now();

        $includedWeight = (float) ($settings->included_weight_kg ?? 1.5);
        $excessWeight = max(0, $parcelWeight - $includedWeight);

        $weightRate = $sameBranch
            ? (float) ($settings->same_branch_excess_weight_rate ?? 20)
            : (float) ($settings->transfer_branch_excess_weight_rate ?? 30);

        $weightCharge = $excessWeight * $weightRate;
        $afterWeight = $baseRate + $weightCharge;

        $fragileMultiplier = $parcelType === 'fragile'
            ? max(1, (float) ($settings->fragile_multiplier ?? 1.05))
            : 1.0;

        $fragileCharge = $afterWeight * ($fragileMultiplier - 1);
        $afterFragile = $afterWeight + $fragileCharge;

        $includedDistance = (float) (
            $settings->included_delivery_distance_km ?? 5
        );

        $extraDistance = max(0, $deliveryDistance - $includedDistance);

        $distanceCharge = $extraDistance * (float) (
            $settings->extra_distance_rate_per_km ?? 6
        );

        $beforeSameDay = $afterFragile + $distanceCharge;

        $sameDayMultiplier = 1.0;

        if ($serviceType === 'same_day') {
            $this->assertBeforeSameDayCutoff(
                $requestedAt,
                (string) ($settings->same_day_cutoff_time ?? '12:00')
            );

            $sameDayMultiplier = $sameBranch
                ? max(
                    1,
                    (float) ($settings->same_day_same_branch_multiplier ?? 1.5)
                )
                : max(
                    1,
                    (float) ($settings->same_day_transfer_branch_multiplier ?? 2)
                );
        }

        $sameDayCharge = $beforeSameDay * ($sameDayMultiplier - 1);
        $afterSameDay = $beforeSameDay + $sameDayCharge;

        $minimumPackets = max(
            1,
            (int) ($settings->minimum_pickup_packet_count ?? 3)
        );

        $lowPacketCharge = $packetCount < $minimumPackets
            ? max(0, (float) ($settings->low_packet_pickup_charge ?? 50))
            : 0.0;

        $subtotalBeforeVat = $afterSameDay + $lowPacketCharge;

        $vatPercentage = max(0, (float) ($settings->vat_percentage ?? 13));
        $vatInclusive = (bool) ($settings->vat_inclusive ?? true);

        if ($vatInclusive) {
            $vatAmount = $vatPercentage > 0
                ? $subtotalBeforeVat * $vatPercentage / (100 + $vatPercentage)
                : 0.0;

            $finalPrice = $subtotalBeforeVat;
        } else {
            $vatAmount = $subtotalBeforeVat * $vatPercentage / 100;
            $finalPrice = $subtotalBeforeVat + $vatAmount;
        }

        return [
            'settings_id' => (int) $settings->id,
            'same_branch' => $sameBranch,
            'base_rate' => $this->money($baseRate),
            'weight' => [
                'parcel_weight_kg' => round($parcelWeight, 3),
                'included_weight_kg' => round($includedWeight, 3),
                'excess_weight_kg' => round($excessWeight, 3),
                'rate_per_kg' => $this->money($weightRate),
                'charge' => $this->money($weightCharge),
            ],
            'fragile' => [
                'applied' => $parcelType === 'fragile',
                'multiplier' => round($fragileMultiplier, 4),
                'charge' => $this->money($fragileCharge),
            ],
            'distance' => [
                'delivery_distance_km' => round($deliveryDistance, 3),
                'included_distance_km' => round($includedDistance, 3),
                'extra_distance_km' => round($extraDistance, 3),
                'rate_per_km' => $this->money(
                    (float) ($settings->extra_distance_rate_per_km ?? 6)
                ),
                'charge' => $this->money($distanceCharge),
            ],
            'same_day' => [
                'applied' => $serviceType === 'same_day',
                'multiplier' => round($sameDayMultiplier, 4),
                'charge' => $this->money($sameDayCharge),
                'cutoff_time' => (string) ($settings->same_day_cutoff_time ?? '12:00'),
            ],
            'pickup_minimum' => [
                'packet_count' => $packetCount,
                'minimum_packet_count' => $minimumPackets,
                'charge' => $this->money($lowPacketCharge),
            ],
            'vat' => [
                'percentage' => round($vatPercentage, 4),
                'inclusive' => $vatInclusive,
                'amount' => $this->money($vatAmount),
            ],
            'subtotal_before_vat' => $this->money($subtotalBeforeVat),
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

        $baseComponent =
            max(0, $baseRate) *
            max(0, (float) $rule->base_rate_percentage) /
            100;

        $distanceComponent =
            max(0, $distanceTravelledKm) *
            max(0, (float) $rule->distance_rate_per_km);

        $fixedCharge = max(0, (float) $rule->fixed_charge);

        return [
            'scenario_code' => $scenarioCode,
            'rule_id' => (int) $rule->id,
            'base_component' => $this->money($baseComponent),
            'distance_component' => $this->money($distanceComponent),
            'fixed_charge' => $this->money($fixedCharge),
            'final_charge' => $this->money(
                $baseComponent + $distanceComponent + $fixedCharge
            ),
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
            array_map('intval', explode(':', $cutoff, 2)),
            2,
            0
        );

        $cutoffAt = $date->copy()->setTime($hour, $minute);

        if ($date->greaterThan($cutoffAt)) {
            throw ValidationException::withMessages([
                'service_type' => [
                    "Same-day delivery is only available before {$cutoff}.",
                ],
            ]);
        }
    }

    private function money(float $value): float
    {
        return round($value, 2);
    }
}
