<?php

namespace Modules\Rate\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PricingEngineService
{
    public function __construct(
        private readonly MainBranchResolverService $branchResolver,
        private readonly InterBranchTransferRateService $transferService
    ) {
    }

    public function calculate(
        array $data,
        ?int $merchantId = null
    ): array {
        $serviceType = $this->serviceType(
            (string) $data['service_type']
        );

        $pickupBranch = $this->branchResolver->resolve(
            (float) $data['pickup_latitude'],
            (float) $data['pickup_longitude']
        );

        $deliveryBranch = $this->branchResolver->resolve(
            (float) $data['delivery_latitude'],
            (float) $data['delivery_longitude']
        );

        $pickup = $this->localFee(
            branchId: (int) $pickupBranch->id,
            serviceTypeId: (int) $serviceType->id,
            merchantId: $merchantId,
            chargeType: 'pickup',
            distanceKm:
                (float) $pickupBranch->resolved_distance_km
        );

        $delivery = $this->localFee(
            branchId: (int) $deliveryBranch->id,
            serviceTypeId: (int) $serviceType->id,
            merchantId: $merchantId,
            chargeType: 'delivery',
            distanceKm:
                (float) $deliveryBranch->resolved_distance_km
        );

        $transfer = $this->transferService->calculate(
            (int) $pickupBranch->id,
            (int) $deliveryBranch->id,
            (int) $serviceType->id,
            $merchantId
        );

        $weight = $this->weightFee(
            (int) $serviceType->id,
            $merchantId,
            (float) $data['parcel_weight']
        );

        $baseSubtotal =
            $pickup['total'] +
            $transfer['rate'] +
            $delivery['total'] +
            $weight['total'];

        $handling = $this->handlingFee(
            (string) (
                $data['parcel_type'] ?? 'non_fragile'
            ),
            (int) $serviceType->id,
            $merchantId,
            $baseSubtotal,
            (float) $data['parcel_weight']
        );

        $pod = $this->codFee(
            (string) $data['payment_type'],
            (float) ($data['pod_amount'] ?? 0),
            (int) $serviceType->id,
            $merchantId
        );

        $finalPrice = round(
            $baseSubtotal +
            $handling['total'] +
            $pod['total'],
            2
        );

        $estimatedHours =
            max(
                1,
                (int) (
                    $serviceType->estimated_hours
                    ?? 24
                )
            ) +
            ((int) $transfer['transfer_count'] * 4);

        return [
            'currency' => 'NPR',

            'service_type' => [
                'id' => (int) $serviceType->id,
                'code' => (string) $serviceType->code,
                'name' => (string) $serviceType->name,
            ],

            'pickup_branch' => [
                'id' => (int) $pickupBranch->id,
                'name' => (string) (
                    $pickupBranch->name
                    ?? "Branch {$pickupBranch->id}"
                ),
                'distance_km' =>
                    (float) $pickupBranch
                        ->resolved_distance_km,
            ],

            'delivery_branch' => [
                'id' => (int) $deliveryBranch->id,
                'name' => (string) (
                    $deliveryBranch->name
                    ?? "Branch {$deliveryBranch->id}"
                ),
                'distance_km' =>
                    (float) $deliveryBranch
                        ->resolved_distance_km,
            ],

            'estimated_hours' => $estimatedHours,
            'sla_due_at' => now()->addHours(
                $estimatedHours
            ),
            'valid_until' => now()->addMinutes(30),

            'breakdown' => [
                'pickup' => $pickup,
                'branch_transfer' => $transfer,
                'delivery' => $delivery,
                'weight' => $weight,
                'handling' => $handling,
                'pod' => $pod,
                'base_subtotal' => round(
                    $baseSubtotal,
                    2
                ),
                'final_price' => $finalPrice,
            ],

            'final_price' => $finalPrice,
        ];
    }

    private function serviceType(
        string $code
    ): object {
        $service = DB::table('service_types')
            ->where('code', strtolower($code))
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

    private function localFee(
        int $branchId,
        int $serviceTypeId,
        ?int $merchantId,
        string $chargeType,
        float $distanceKm
    ): array {
        $rule = $this->branchRule(
            $branchId,
            $serviceTypeId,
            $merchantId,
            $chargeType
        );

        if (!$rule) {
            throw ValidationException::withMessages([
                "{$chargeType}_address" => [
                    ucfirst($chargeType) .
                    ' pricing is not configured.',
                ],
            ]);
        }

        if (
            $rule->maximum_radius_km !== null &&
            $distanceKm >
            (float) $rule->maximum_radius_km
        ) {
            throw ValidationException::withMessages([
                "{$chargeType}_address" => [
                    ucfirst($chargeType) .
                    ' location is outside the service radius.',
                ],
            ]);
        }

        $baseRadius =
            (float) $rule->base_radius_km;

        $baseFee =
            (float) $rule->base_fee;

        $additionalDistance = max(
            0,
            $distanceKm - $baseRadius
        );

        $unit = max(
            0.001,
            (float) $rule
                ->additional_distance_unit_km
        );

        $units = $additionalDistance > 0
            ? (int) ceil(
                $additionalDistance / $unit
            )
            : 0;

        $additionalFee =
            $units *
            (float) $rule
                ->additional_distance_fee;

        return [
            'rule_id' => (int) $rule->id,
            'charge_type' => $chargeType,
            'distance_km' => round(
                $distanceKm,
                3
            ),
            'base_radius_km' => $baseRadius,
            'base_fee' => round($baseFee, 2),
            'additional_distance_km' => round(
                $additionalDistance,
                3
            ),
            'additional_distance_unit_km' => $unit,
            'additional_units' => $units,
            'additional_unit_fee' => round(
                (float) $rule
                    ->additional_distance_fee,
                2
            ),
            'additional_fee' => round(
                $additionalFee,
                2
            ),
            'total' => round(
                $baseFee + $additionalFee,
                2
            ),
        ];
    }

    private function branchRule(
        int $branchId,
        int $serviceTypeId,
        ?int $merchantId,
        string $chargeType
    ): ?object {
        if ($merchantId !== null) {
            $merchantRule = DB::table(
                'branch_pricing_rules'
            )
                ->where('branch_id', $branchId)
                ->where(
                    'service_type_id',
                    $serviceTypeId
                )
                ->where(
                    'merchant_id',
                    $merchantId
                )
                ->where(
                    'charge_type',
                    $chargeType
                )
                ->where('is_active', true)
                ->orderByDesc('id')
                ->first();

            if ($merchantRule) {
                return $merchantRule;
            }
        }

        return DB::table('branch_pricing_rules')
            ->where('branch_id', $branchId)
            ->where(
                'service_type_id',
                $serviceTypeId
            )
            ->whereNull('merchant_id')
            ->where('charge_type', $chargeType)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();
    }

    private function weightFee(
        int $serviceTypeId,
        ?int $merchantId,
        float $weightKg
    ): array {
        $rule = $this->priorityRule(
            'weight_rate_rules',
            [],
            $serviceTypeId,
            $merchantId
        );

        if (!$rule) {
            throw ValidationException::withMessages([
                'parcel_weight' => [
                    'Weight pricing is not configured.',
                ],
            ]);
        }

        if (
            $rule->maximum_weight_kg !== null &&
            $weightKg >
            (float) $rule->maximum_weight_kg
        ) {
            throw ValidationException::withMessages([
                'parcel_weight' => [
                    'Parcel exceeds maximum supported weight.',
                ],
            ]);
        }

        $baseWeight =
            (float) $rule->base_weight_kg;

        $baseFee =
            (float) $rule->base_weight_fee;

        $additionalWeight = max(
            0,
            $weightKg - $baseWeight
        );

        $unit = max(
            0.001,
            (float) $rule
                ->additional_weight_unit_kg
        );

        $units = $additionalWeight > 0
            ? (int) ceil(
                $additionalWeight / $unit
            )
            : 0;

        $additionalFee =
            $units *
            (float) $rule
                ->additional_weight_fee;

        return [
            'rule_id' => (int) $rule->id,
            'parcel_weight_kg' => round(
                $weightKg,
                3
            ),
            'base_weight_kg' => $baseWeight,
            'base_weight_fee' => round(
                $baseFee,
                2
            ),
            'additional_weight_kg' => round(
                $additionalWeight,
                3
            ),
            'additional_weight_unit_kg' =>
                $unit,
            'additional_units' => $units,
            'additional_unit_fee' => round(
                (float) $rule
                    ->additional_weight_fee,
                2
            ),
            'additional_fee' => round(
                $additionalFee,
                2
            ),
            'total' => round(
                $baseFee + $additionalFee,
                2
            ),
        ];
    }

    private function handlingFee(
        string $handlingType,
        int $serviceTypeId,
        ?int $merchantId,
        float $subtotal,
        float $weightKg
    ): array {
        if ($handlingType === 'non_fragile') {
            return [
                'handling_type' =>
                    'non_fragile',
                'calculation_type' =>
                    'none',
                'total' => 0.0,
            ];
        }

        $rule = $this->priorityRule(
            'parcel_handling_rates',
            [
                'handling_type' =>
                    $handlingType,
            ],
            $serviceTypeId,
            $merchantId
        );

        if (!$rule) {
            throw ValidationException::withMessages([
                'parcel_type' => [
                    'Fragile pricing is not configured.',
                ],
            ]);
        }

        $type =
            (string) $rule->calculation_type;

        $fee = match ($type) {
            'fixed' =>
                (float) ($rule->fixed_fee ?? 0),

            'percentage' =>
                $subtotal *
                (
                    (float) (
                        $rule->percentage ?? 0
                    ) / 100
                ),

            'per_kg' =>
                $weightKg *
                (float) (
                    $rule->per_kg_fee ?? 0
                ),

            'percentage_with_minimum' =>
                max(
                    (float) (
                        $rule->minimum_fee ?? 0
                    ),
                    $subtotal *
                    (
                        (float) (
                            $rule->percentage ?? 0
                        ) / 100
                    )
                ),

            default =>
                throw ValidationException::withMessages([
                    'parcel_type' => [
                        'Invalid fragile pricing type.',
                    ],
                ]),
        };

        return [
            'rule_id' => (int) $rule->id,
            'handling_type' => $handlingType,
            'calculation_type' => $type,
            'calculation_base' => round(
                $subtotal,
                2
            ),
            'total' => round($fee, 2),
        ];
    }

    private function codFee(
        string $paymentType,
        float $codAmount,
        int $serviceTypeId,
        ?int $merchantId
    ): array {
        if ($paymentType !== 'pod') {
            return [
                'payment_type' => 'prepaid',
                'calculation_type' => 'none',
                'pod_amount' => 0.0,
                'total' => 0.0,
            ];
        }

        $rule = $this->priorityRule(
            'pod_rate_rules',
            [],
            $serviceTypeId,
            $merchantId
        );

        if (!$rule) {
            return [
                'payment_type' => 'pod',
                'calculation_type' => 'none',
                'pod_amount' => round(
                    $codAmount,
                    2
                ),
                'total' => 0.0,
            ];
        }

        $type =
            (string) $rule->calculation_type;

        $fee = match ($type) {
            'fixed' =>
                (float) ($rule->fixed_fee ?? 0),

            'percentage' =>
                $codAmount *
                (
                    (float) (
                        $rule->percentage ?? 0
                    ) / 100
                ),

            'percentage_with_minimum' =>
                max(
                    (float) (
                        $rule->minimum_fee ?? 0
                    ),
                    $codAmount *
                    (
                        (float) (
                            $rule->percentage ?? 0
                        ) / 100
                    )
                ),

            default => 0.0,
        };

        if ($rule->maximum_fee !== null) {
            $fee = min(
                $fee,
                (float) $rule->maximum_fee
            );
        }

        return [
            'rule_id' => (int) $rule->id,
            'payment_type' => 'pod',
            'calculation_type' => $type,
            'pod_amount' => round(
                $codAmount,
                2
            ),
            'total' => round($fee, 2),
        ];
    }

    private function priorityRule(
        string $table,
        array $required,
        int $serviceTypeId,
        ?int $merchantId
    ): ?object {
        $priorities = [];

        if ($merchantId !== null) {
            $priorities[] = [
                'merchant_id' => $merchantId,
                'service_type_id' =>
                    $serviceTypeId,
            ];

            $priorities[] = [
                'merchant_id' => $merchantId,
                'service_type_id' => null,
            ];
        }

        $priorities[] = [
            'merchant_id' => null,
            'service_type_id' =>
                $serviceTypeId,
        ];

        $priorities[] = [
            'merchant_id' => null,
            'service_type_id' => null,
        ];

        foreach ($priorities as $priority) {
            $query = DB::table($table)
                ->where($required)
                ->where('is_active', true);

            $priority['merchant_id'] === null
                ? $query->whereNull('merchant_id')
                : $query->where(
                    'merchant_id',
                    $priority['merchant_id']
                );

            $priority['service_type_id'] === null
                ? $query->whereNull(
                    'service_type_id'
                )
                : $query->where(
                    'service_type_id',
                    $priority['service_type_id']
                );

            $result = $query
                ->orderByDesc('id')
                ->first();

            if ($result) {
                return $result;
            }
        }

        return null;
    }
}