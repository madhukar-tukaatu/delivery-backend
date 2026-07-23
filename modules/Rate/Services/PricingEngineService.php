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
         * Official pricing is currently global rather than
         * merchant-specific.
         */
        unset($merchantId);

        $settings = $this->activeSettings();

        $serviceType = $this->serviceType(
            (string) $data['service_type']
        );

        /*
         * Resolve the responsible main branch for the pickup
         * and delivery coordinates.
         *
         * Subbranches may handle operations, but route pricing
         * is calculated using the responsible main branches.
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

        $pickupDistanceKm = max(
            0,
            (float) (
                $pickupBranch->resolved_distance_km
                ?? 0
            )
        );

        $deliveryDistanceKm = max(
            0,
            (float) (
                $deliveryBranch->resolved_distance_km
                ?? 0
            )
        );

        /*
         * Step 1:
         * Find the official branch-to-branch route rate.
         */
        $routeRate = $this->routeBaseRate(
            $pickupBranchId,
            $deliveryBranchId
        );

        $baseRate = max(
            0,
            (float) $routeRate->base_rate
        );

        /*
         * Step 2:
         * Calculate the parcel's chargeable weight.
         *
         * When all dimensions are available:
         * chargeable weight = higher of actual weight and
         * volumetric weight.
         *
         * When dimensions are unavailable or incomplete:
         * chargeable weight = actual weight.
         */
        $weightMeasurement =
            $this->calculateChargeableWeight(
                data: $data,
                settings: $settings
            );

        /*
         * Apply additional-weight pricing above the weight
         * included in the route base rate.
         */
        $weight = $this->weightCharge(
            weightMeasurement: $weightMeasurement,
            isSameBranch: $isSameBranch,
            settings: $settings
        );

        $subtotalBeforeFragile =
            $baseRate +
            $weight['total'];

        /*
         * Step 3:
         * Fragile parcel:
         *
         * Above result × 1.05
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
         * First 5 KM from the destination branch is included.
         * Additional distance is charged at Rs. 6 per KM.
         */
        $distance =
            $this->extraDeliveryDistanceCharge(
                deliveryDistanceKm: $deliveryDistanceKm,
                settings: $settings
            );

        $subtotalBeforeSameDay =
            $subtotalAfterFragile +
            $distance['total'];

        /*
         * Step 5:
         * Same-day delivery:
         *
         * Same branch: above result × 1.5
         * Other branch: above result × 2
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
         * Add Rs. 50 when the pickup contains fewer than
         * three packets.
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
                 * Official route rates already include VAT.
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
                'id' =>
                    $pickupBranchId,

                'name' =>
                    (string) (
                        $pickupBranch->name
                        ?? "Branch {$pickupBranchId}"
                    ),

                'distance_km' =>
                    round(
                        $pickupDistanceKm,
                        3
                    ),
            ],

            'delivery_branch' => [
                'id' =>
                    $deliveryBranchId,

                'name' =>
                    (string) (
                        $deliveryBranch->name
                        ?? "Branch {$deliveryBranchId}"
                    ),

                'distance_km' =>
                    round(
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
                    round(
                        $baseRate,
                        2
                    ),
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
                        round(
                            $baseRate,
                            2
                        ),

                    'total' =>
                        round(
                            $baseRate,
                            2
                        ),
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
            ->where(
                'code',
                $normalizedCode
            )
            ->where(
                'is_active',
                true
            )
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
     * Retrieve the official route base rate for the
     * resolved pickup and delivery main branches.
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
            ->where(
                'is_active',
                true
            )
            ->orderByDesc('id')
            ->first();

        /*
         * Use the reverse route as a fallback when an
         * individual reverse rate is not configured.
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
                ->where(
                    'is_active',
                    true
                )
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
     * Calculate actual weight, optional volumetric weight
     * and the final chargeable weight.
     *
     * Dimensions are optional.
     *
     * When all dimensions are provided:
     *
     * Volumetric weight =
     * length × width × height ÷ volumetric divisor
     *
     * Chargeable weight =
     * higher of actual and volumetric weight
     *
     * When dimensions are missing or incomplete:
     *
     * Chargeable weight = actual weight
     */
    private function calculateChargeableWeight(
        array $data,
        object $settings
    ): array {
        $actualWeightKg = max(
            0,
            (float) (
                $data['parcel_weight']
                ?? 0
            )
        );

        if ($actualWeightKg <= 0) {
            throw ValidationException::withMessages([
                'parcel_weight' => [
                    'The parcel actual weight must be greater than zero.',
                ],
            ]);
        }

        /*
         * Support more than one possible field name so that
         * quotations from different products can use the
         * same pricing service.
         */
        $lengthCm = $this->optionalDimension(
            data: $data,
            possibleKeys: [
                'parcel_length_cm',
                'length_cm',
                'parcel_length',
                'length',
            ]
        );

        $widthCm = $this->optionalDimension(
            data: $data,
            possibleKeys: [
                'parcel_width_cm',
                'width_cm',
                'parcel_width',
                'width',
            ]
        );

        $heightCm = $this->optionalDimension(
            data: $data,
            possibleKeys: [
                'parcel_height_cm',
                'height_cm',
                'parcel_height',
                'height',
            ]
        );

        $hasAnyDimension =
            $lengthCm !== null ||
            $widthCm !== null ||
            $heightCm !== null;

        $hasCompleteDimensions =
            $lengthCm !== null &&
            $lengthCm > 0 &&
            $widthCm !== null &&
            $widthCm > 0 &&
            $heightCm !== null &&
            $heightCm > 0;

        /*
         * Keep the divisor configurable.
         *
         * When the database field has not yet been added,
         * the service temporarily falls back to 5000.
         */
        $volumetricDivisor = max(
            1,
            (float) (
                $settings->volumetric_divisor
                ?? 5000
            )
        );

        $volumetricWeightKg = null;
        $chargeableWeightKg = $actualWeightKg;
        $weightSource = 'actual_weight';
        $volumetricApplied = false;

        if ($hasCompleteDimensions) {
            $volumetricWeightKg =
                (
                    $lengthCm *
                    $widthCm *
                    $heightCm
                ) /
                $volumetricDivisor;

            $volumetricApplied = true;

            if (
                $volumetricWeightKg >
                $actualWeightKg
            ) {
                $chargeableWeightKg =
                    $volumetricWeightKg;

                $weightSource =
                    'volumetric_weight';
            }
        }

        if ($hasCompleteDimensions) {
            $volumetricStatus =
                'calculated';
        } elseif ($hasAnyDimension) {
            /*
             * One or two dimensions were sent, but the
             * complete L × W × H measurement was unavailable.
             */
            $volumetricStatus =
                'incomplete_dimensions';
        } else {
            $volumetricStatus =
                'not_provided';
        }

        return [
            /*
             * Raw values are retained internally so that the
             * charge is not affected by premature rounding.
             */
            'actual_weight_kg' =>
                $actualWeightKg,

            'volumetric_weight_kg' =>
                $volumetricWeightKg,

            'chargeable_weight_kg' =>
                $chargeableWeightKg,

            'weight_source' =>
                $weightSource,

            'volumetric_applied' =>
                $volumetricApplied,

            'volumetric_status' =>
                $volumetricStatus,

            'volumetric_divisor' =>
                $volumetricDivisor,

            'dimensions' => [
                'length_cm' =>
                    $lengthCm,

                'width_cm' =>
                    $widthCm,

                'height_cm' =>
                    $heightCm,
            ],
        ];
    }

    /**
     * Find an optional parcel dimension using the supported
     * request-field names.
     *
     * Missing and empty values return null.
     */
    private function optionalDimension(
        array $data,
        array $possibleKeys
    ): ?float {
        foreach ($possibleKeys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $value = $data[$key];

            if (
                $value === null ||
                $value === ''
            ) {
                continue;
            }

            $dimension = (float) $value;

            if ($dimension <= 0) {
                return null;
            }

            return $dimension;
        }

        return null;
    }

    /**
     * Apply the additional-weight charge using the final
     * chargeable weight.
     *
     * Base rate includes up to the configured weight,
     * normally 1.5 KG.
     *
     * Same branch:
     * Rs. 20 per additional KG.
     *
     * Other branch:
     * Rs. 30 per additional KG.
     */
    private function weightCharge(
        array $weightMeasurement,
        bool $isSameBranch,
        object $settings
    ): array {
        $actualWeightKg = max(
            0,
            (float)
            $weightMeasurement['actual_weight_kg']
        );

        $volumetricWeightKg =
            $weightMeasurement[
                'volumetric_weight_kg'
            ];

        $chargeableWeightKg = max(
            0,
            (float)
            $weightMeasurement[
                'chargeable_weight_kg'
            ]
        );

        $includedWeightKg = max(
            0,
            (float)
            $settings->included_weight_kg
        );

        $ratePerKg = $isSameBranch
            ? max(
                0,
                (float)
                $settings
                    ->same_branch_weight_rate
            )
            : max(
                0,
                (float)
                $settings
                    ->other_branch_weight_rate
            );

        $excessWeightKg = max(
            0,
            $chargeableWeightKg -
            $includedWeightKg
        );

        /*
         * The supplied pricing sheet charges decimal excess
         * weight directly:
         *
         * Base rate + [(3.2 - 1.5) × weight rate]
         *
         * Therefore ceil() is not used.
         */
        $charge =
            $excessWeightKg *
            $ratePerKg;

        return [
            'actual_weight_kg' =>
                round(
                    $actualWeightKg,
                    3
                ),

            'volumetric_weight_kg' =>
                $volumetricWeightKg !== null
                    ? round(
                        (float)
                        $volumetricWeightKg,
                        3
                    )
                    : null,

            'chargeable_weight_kg' =>
                round(
                    $chargeableWeightKg,
                    3
                ),

            'weight_source' =>
                (string)
                $weightMeasurement[
                    'weight_source'
                ],

            'volumetric_applied' =>
                (bool)
                $weightMeasurement[
                    'volumetric_applied'
                ],

            'volumetric_status' =>
                (string)
                $weightMeasurement[
                    'volumetric_status'
                ],

            'dimensions' => [
                'length_cm' =>
                    $weightMeasurement[
                        'dimensions'
                    ]['length_cm'] !== null
                        ? round(
                            (float)
                            $weightMeasurement[
                                'dimensions'
                            ]['length_cm'],
                            2
                        )
                        : null,

                'width_cm' =>
                    $weightMeasurement[
                        'dimensions'
                    ]['width_cm'] !== null
                        ? round(
                            (float)
                            $weightMeasurement[
                                'dimensions'
                            ]['width_cm'],
                            2
                        )
                        : null,

                'height_cm' =>
                    $weightMeasurement[
                        'dimensions'
                    ]['height_cm'] !== null
                        ? round(
                            (float)
                            $weightMeasurement[
                                'dimensions'
                            ]['height_cm'],
                            2
                        )
                        : null,
            ],

            'volumetric_divisor' =>
                round(
                    (float)
                    $weightMeasurement[
                        'volumetric_divisor'
                    ],
                    2
                ),

            'included_weight_kg' =>
                round(
                    $includedWeightKg,
                    3
                ),

            'excess_weight_kg' =>
                round(
                    $excessWeightKg,
                    3
                ),

            'additional_weight_applied' =>
                $excessWeightKg > 0,

            'route_type' =>
                $isSameBranch
                    ? 'same_branch'
                    : 'other_branch',

            'rate_per_kg' =>
                round(
                    $ratePerKg,
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
     * Apply the fragile-item multiplier.
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
            $settings->fragile_multiplier
        );

        $multipliedAmount =
            $calculationBase *
            $multiplier;

        $charge =
            $multipliedAmount -
            $calculationBase;

        return [
            'applied' =>
                true,

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
                round(
                    $charge,
                    2
                ),
        ];
    }

    /**
     * Charge for delivery distance beyond the included
     * destination-branch radius.
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

            'applied' =>
                $extraDistanceKm > 0,

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
     * Apply the same-day delivery multiplier.
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
                'applied' =>
                    false,

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
            $settings->same_day_cutoff_time
        );

        $multiplier = $isSameBranch
            ? max(
                1,
                (float)
                $settings
                    ->same_branch_sdd_multiplier
            )
            : max(
                1,
                (float)
                $settings
                    ->other_branch_sdd_multiplier
            );

        $multipliedAmount =
            $calculationBase *
            $multiplier;

        $charge =
            $multipliedAmount -
            $calculationBase;

        return [
            'applied' =>
                true,

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
     * Reject same-day requests submitted at or after the
     * configured cutoff time.
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
     * Charge Rs. 50 when a pickup contains fewer than the
     * configured minimum packet count.
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
            ? max(
                0,
                (float)
                $settings
                    ->low_packet_pickup_charge
            )
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