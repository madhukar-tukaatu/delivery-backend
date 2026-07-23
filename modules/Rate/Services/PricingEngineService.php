<?php

namespace Modules\Rate\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PricingEngineService
{
    public function __construct(
        private readonly MainBranchResolverService $branchResolver
    ) {}

    public function calculate(
        array $data,
        ?int $merchantId = null
    ): array {
        /*
         * Merchant ID is currently not used because the official
         * pricing rules are global, not merchant-specific.
         */
        unset($merchantId);

        $settings = $this->activeSettings();

        $serviceType = $this->serviceType(
            (string) $data['service_type']
        );

        /*
         * Resolve the main branches responsible for pickup
         * and delivery coordinates.
         */
        $pickupBranch = $this->branchResolver->resolve(
            (float) $data['pickup_latitude'],
            (float) $data['pickup_longitude']
        );

        $deliveryBranch = $this->branchResolver->resolve(
            (float) $data['delivery_latitude'],
            (float) $data['delivery_longitude']
        );

        $pickupBranchId = (int) $pickupBranch->id;
        $deliveryBranchId = (int) $deliveryBranch->id;

        $isSameBranch =
            $pickupBranchId === $deliveryBranchId;

        /*
         * The resolver should return the distance between the
         * resolved delivery branch and the delivery coordinate.
         */
        $pickupDistanceKm = max(
            0,
            (float) (
                $pickupBranch->resolved_distance_km ?? 0
            )
        );

        $deliveryDistanceKm = max(
            0,
            (float) (
                $deliveryBranch->resolved_distance_km ?? 0
            )
        );

        /*
         * Step 1:
         * Find the official route base rate.
         */
        $routeRate = $this->routeBaseRate(
            $pickupBranchId,
            $deliveryBranchId
        );

        $baseRate = (float) $routeRate->base_rate;

        /*
         * Step 2:
         * Calculate excess weight above 1.5 KG.
         *
         * Same branch: Rs. 20 per KG
         * Other branch: Rs. 30 per KG
         */
        $weight = $this->weightCharge(
            parcelWeightKg: (float) $data['parcel_weight'],

            isSameBranch: $isSameBranch,

            settings: $settings
        );

        $subtotalBeforeFragile =
            $baseRate +
            $weight['total'];

        /*
         * Step 3:
         * Apply fragile multiplier to:
         *
         * Base rate + excess weight charge
         */
        $fragile = $this->fragileCharge(
            parcelType: (string) (
                $data['parcel_type']
                ?? 'non_fragile'
            ),

            calculationBase: $subtotalBeforeFragile,

            settings: $settings
        );

        $subtotalAfterFragile =
            $subtotalBeforeFragile +
            $fragile['total'];

        /*
         * Step 4:
         * First 5 KM from destination branch is included.
         * Charge Rs. 6 per extra KM.
         */
        $distance = $this->extraDeliveryDistanceCharge(
            deliveryDistanceKm: $deliveryDistanceKm,

            settings: $settings
        );

        $subtotalBeforeSameDay =
            $subtotalAfterFragile +
            $distance['total'];

        /*
         * Step 5:
         * Apply same-day multiplier only when service_type
         * is same_day.
         */
        $sameDay = $this->sameDayCharge(
            serviceCode: (string) $serviceType->code,

            calculationBase: $subtotalBeforeSameDay,

            isSameBranch: $isSameBranch,

            settings: $settings
        );

        $subtotalAfterSameDay =
            $subtotalBeforeSameDay +
            $sameDay['total'];

        /*
         * Step 6:
         * Charge Rs. 50 when pickup contains fewer than
         * 3 packets.
         */
        $minimumPacketCharge =
            $this->minimumPacketCharge(
                packetCount: (int) (
                    $data['packet_count']
                    ?? 1
                ),

                settings: $settings
            );

        $finalPrice = round(
            $subtotalAfterSameDay +
                $minimumPacketCharge['total'],
            2
        );

        $estimatedHours = max(
            1,
            (int) (
                $serviceType->estimated_hours
                ?? 24
            )
        );

        return [
            'currency' => 'NPR',

            'vat' => [
                'inclusive' =>
                (bool) $settings->vat_inclusive,

                'percentage' =>
                (float) $settings->vat_percentage,

                /*
                 * The official rates already include VAT.
                 */
                'additional_vat_added' => false,
            ],

            'service_type' => [
                'id' =>
                (int) $serviceType->id,

                'code' =>
                (string) $serviceType->code,

                'name' =>
                (string) $serviceType->name,
            ],

            'pickup_branch' => [
                'id' => $pickupBranchId,

                'name' => (string) (
                    $pickupBranch->name
                    ?? "Branch {$pickupBranchId}"
                ),

                'distance_km' => round(
                    $pickupDistanceKm,
                    3
                ),
            ],

            'delivery_branch' => [
                'id' => $deliveryBranchId,

                'name' => (string) (
                    $deliveryBranch->name
                    ?? "Branch {$deliveryBranchId}"
                ),

                'distance_km' => round(
                    $deliveryDistanceKm,
                    3
                ),
            ],

            'route' => [
                'route_rate_id' =>
                (int) $routeRate->id,

                'pickup_branch_id' =>
                $pickupBranchId,

                'delivery_branch_id' =>
                $deliveryBranchId,

                'same_branch' =>
                $isSameBranch,

                'base_rate' =>
                round($baseRate, 2),
            ],

            'estimated_hours' =>
            $estimatedHours,

            'sla_due_at' =>
            now()->addHours(
                $estimatedHours
            ),

            'valid_until' =>
            now()->addMinutes(30),

            'breakdown' => [
                'route_base_rate' => [
                    'rule_id' =>
                    (int) $routeRate->id,

                    'amount' =>
                    round($baseRate, 2),

                    'total' =>
                    round($baseRate, 2),
                ],

                'weight' =>
                $weight,

                'subtotal_before_fragile' =>
                round(
                    $subtotalBeforeFragile,
                    2
                ),

                'fragile' =>
                $fragile,

                'subtotal_after_fragile' =>
                round(
                    $subtotalAfterFragile,
                    2
                ),

                'extra_delivery_distance' =>
                $distance,

                'subtotal_before_same_day' =>
                round(
                    $subtotalBeforeSameDay,
                    2
                ),

                'same_day' =>
                $sameDay,

                'subtotal_after_same_day' =>
                round(
                    $subtotalAfterSameDay,
                    2
                ),

                'minimum_packet_charge' =>
                $minimumPacketCharge,

                'final_price' =>
                $finalPrice,
            ],

            'final_price' =>
            $finalPrice,
        ];
    }

    /**
     * Retrieve the active global pricing configuration.
     */
    private function activeSettings(): object
    {
        $settings = DB::table('pricing_settings')
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();

        if (!$settings) {
            throw ValidationException::withMessages([
                'pricing' => [
                    'Pricing settings are not configured.',
                ],
            ]);
        }

        return $settings;
    }

    /**
     * Resolve the requested service type.
     */
    private function serviceType(
        string $code
    ): object {
        $normalizedCode = strtolower(
            trim($code)
        );

        $service = DB::table('service_types')
            ->where('code', $normalizedCode)
            ->where('is_active', true)
            ->first();

        if (!$service) {
            throw ValidationException::withMessages([
                'service_type' => [
                    'The selected service type is unavailable.',
                ],
            ]);
        }

        return $service;
    }

    /**
     * Retrieve the official base rate for the resolved
     * pickup and delivery branches.
     */
    private function routeBaseRate(
        int $pickupBranchId,
        int $deliveryBranchId
    ): object {
        $rule = DB::table('branch_route_rates')
            ->where(
                'pickup_branch_id',
                $pickupBranchId
            )
            ->where(
                'delivery_branch_id',
                $deliveryBranchId
            )
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();

        /*
         * Fallback to reverse route when a separate reverse
         * route is not configured.
         */
        if (!$rule) {
            $rule = DB::table('branch_route_rates')
                ->where(
                    'pickup_branch_id',
                    $deliveryBranchId
                )
                ->where(
                    'delivery_branch_id',
                    $pickupBranchId
                )
                ->where('is_active', true)
                ->orderByDesc('id')
                ->first();
        }

        if (!$rule) {
            throw ValidationException::withMessages([
                'delivery_address' => [
                    'Base rate is not configured for the selected branch route.',
                ],
            ]);
        }

        return $rule;
    }

    /**
     * Calculate excess-weight pricing.
     *
     * Base rate includes up to 1.5 KG.
     * Same branch: Rs. 20 per excess KG.
     * Other branch: Rs. 30 per excess KG.
     */
    private function weightCharge(
        float $parcelWeightKg,
        bool $isSameBranch,
        object $settings
    ): array {
        $includedWeightKg = max(
            0,
            (float)
            $settings->included_weight_kg
        );

        $ratePerKg = $isSameBranch
            ? (float)
            $settings
                ->same_branch_weight_rate
            : (float)
            $settings
                ->other_branch_weight_rate;

        $excessWeightKg = max(
            0,
            $parcelWeightKg -
                $includedWeightKg
        );

        /*
         * No ceil() is used.
         * Decimal excess weight is charged directly.
         */
        $charge =
            $excessWeightKg *
            $ratePerKg;

        return [
            'parcel_weight_kg' =>
            round($parcelWeightKg, 3),

            'included_weight_kg' =>
            round($includedWeightKg, 3),

            'excess_weight_kg' =>
            round($excessWeightKg, 3),

            'route_type' =>
            $isSameBranch
                ? 'same_branch'
                : 'other_branch',

            'rate_per_kg' =>
            round($ratePerKg, 2),

            'total' =>
            round($charge, 2),
        ];
    }

    /**
     * Apply the fragile parcel multiplier.
     */
    private function fragileCharge(
        string $parcelType,
        float $calculationBase,
        object $settings
    ): array {
        $normalizedType = strtolower(
            trim($parcelType)
        );

        if ($normalizedType !== 'fragile') {
            return [
                'applied' => false,

                'parcel_type' =>
                'non_fragile',

                'multiplier' =>
                1.0,

                'calculation_base' =>
                round(
                    $calculationBase,
                    2
                ),

                'total' =>
                0.0,
            ];
        }

        $multiplier = max(
            1,
            (float)
            $settings
                ->fragile_multiplier
        );

        $multipliedAmount =
            $calculationBase *
            $multiplier;

        $charge =
            $multipliedAmount -
            $calculationBase;

        return [
            'applied' => true,

            'parcel_type' =>
            'fragile',

            'multiplier' =>
            $multiplier,

            'calculation_base' =>
            round(
                $calculationBase,
                2
            ),

            'multiplied_amount' =>
            round(
                $multipliedAmount,
                2
            ),

            'total' =>
            round($charge, 2),
        ];
    }

    /**
     * Charge for delivery distance beyond the included
     * destination radius.
     */
    private function extraDeliveryDistanceCharge(
        float $deliveryDistanceKm,
        object $settings
    ): array {
        $includedDistanceKm = max(
            0,
            (float)
            $settings
                ->included_delivery_distance_km
        );

        $ratePerKm = max(
            0,
            (float)
            $settings
                ->extra_distance_rate_per_km
        );

        $extraDistanceKm = max(
            0,
            $deliveryDistanceKm -
                $includedDistanceKm
        );

        $charge =
            $extraDistanceKm *
            $ratePerKm;

        return [
            'delivery_distance_km' =>
            round(
                $deliveryDistanceKm,
                3
            ),

            'included_distance_km' =>
            round(
                $includedDistanceKm,
                3
            ),

            'extra_distance_km' =>
            round(
                $extraDistanceKm,
                3
            ),

            'rate_per_km' =>
            round(
                $ratePerKm,
                2
            ),

            'total' =>
            round(
                $charge,
                2
            ),
        ];
    }

    /**
     * Apply same-day multiplier when the service type
     * is same_day.
     */
    private function sameDayCharge(
        string $serviceCode,
        float $calculationBase,
        bool $isSameBranch,
        object $settings
    ): array {
        $normalizedCode = strtolower(
            trim($serviceCode)
        );

        if ($normalizedCode !== 'same_day') {
            return [
                'applied' => false,

                'route_type' =>
                $isSameBranch
                    ? 'same_branch'
                    : 'other_branch',

                'multiplier' =>
                1.0,

                'calculation_base' =>
                round(
                    $calculationBase,
                    2
                ),

                'total' =>
                0.0,
            ];
        }

        $this->validateSameDayCutoff(
            (string)
            $settings
                ->same_day_cutoff_time
        );

        $multiplier = $isSameBranch
            ? (float)
            $settings
                ->same_branch_sdd_multiplier
            : (float)
            $settings
                ->other_branch_sdd_multiplier;

        $multipliedAmount =
            $calculationBase *
            $multiplier;

        $charge =
            $multipliedAmount -
            $calculationBase;

        return [
            'applied' => true,

            'route_type' =>
            $isSameBranch
                ? 'same_branch'
                : 'other_branch',

            'multiplier' =>
            $multiplier,

            'calculation_base' =>
            round(
                $calculationBase,
                2
            ),

            'multiplied_amount' =>
            round(
                $multipliedAmount,
                2
            ),

            'total' =>
            round(
                $charge,
                2
            ),
        ];
    }

    /**
     * Reject same-day delivery requests submitted at or
     * after the configured cutoff time.
     */
    private function validateSameDayCutoff(
        string $cutoffTime
    ): void {
        $timezone = config(
            'app.timezone',
            'Asia/Kathmandu'
        );

        $now = now($timezone);

        $cutoff = Carbon::parse(
            $now->format('Y-m-d') .
                ' ' .
                $cutoffTime,
            $timezone
        );

        if (
            $now->greaterThanOrEqualTo(
                $cutoff
            )
        ) {
            throw ValidationException::withMessages([
                'service_type' => [
                    'Same-day delivery requests must be submitted before the configured cutoff time.',
                ],
            ]);
        }
    }

    /**
     * Charge Rs. 50 when pickup has fewer than the
     * minimum configured packet count.
     */
    private function minimumPacketCharge(
        int $packetCount,
        object $settings
    ): array {
        $packetCount = max(
            1,
            $packetCount
        );

        $minimumPackets = max(
            1,
            (int)
            $settings
                ->minimum_pickup_packets
        );

        $isApplied =
            $packetCount <
            $minimumPackets;

        $charge = $isApplied
            ? (float)
            $settings
                ->low_packet_pickup_charge
            : 0.0;

        return [
            'packet_count' =>
            $packetCount,

            'minimum_packets' =>
            $minimumPackets,

            'applied' =>
            $isApplied,

            'total' =>
            round(
                $charge,
                2
            ),
        ];
    }
}