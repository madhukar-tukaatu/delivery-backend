<?php

declare(strict_types=1);

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
         * Pricing is currently global rather than merchant-specific.
         */
        unset($merchantId);

        $settings = $this->activeSettings();

        $serviceType = $this->serviceType(
            (string) ($data['service_type'] ?? '')
        );

        /*
         * Main branches control route pricing. Subbranches may handle
         * operations, but they do not create separate route prices.
         */
        $pickupBranch = $this->branchResolver->resolve(
            (float) ($data['pickup_latitude'] ?? 0),
            (float) ($data['pickup_longitude'] ?? 0)
        );

        $deliveryBranch = $this->branchResolver->resolve(
            (float) ($data['delivery_latitude'] ?? 0),
            (float) ($data['delivery_longitude'] ?? 0)
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
         * Step 1: One branch route base rate per shipment.
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
         * Step 2: Build one physical packet for every item.
         *
         * Supported input priority:
         * 1. packets[] - already separated physical packets
         * 2. products[] - quantity is expanded into individual packets
         * 3. legacy top-level parcel fields - one physical packet only
         */
        $packetResult = $this->calculatePackets(
            data: $data,
            settings: $settings
        );

        $packetInputSource =
            $packetResult['input_source'];

        $packets =
            $packetResult['packets'];

        $packetCount = count($packets);

        if (
            array_key_exists('packet_count', $data) &&
            (int) $data['packet_count'] !== $packetCount
        ) {
            throw ValidationException::withMessages([
                'packet_count' => [
                    "The packet count must be {$packetCount} because every physical item is handled as a separate packet.",
                ],
            ]);
        }

        /*
         * Step 3: Sum chargeable weights from all packets.
         */
        $totalActualWeightKg = array_sum(
            array_column(
                $packets,
                'actual_weight_kg'
            )
        );

        $totalVolumetricWeightKg = array_sum(
            array_map(
                static fn (array $packet): float =>
                    (float) (
                        $packet['volumetric_weight_kg']
                        ?? 0
                    ),
                $packets
            )
        );

        $totalChargeableWeightKg = array_sum(
            array_column(
                $packets,
                'chargeable_weight_kg'
            )
        );

        /*
         * Step 4: The included base weight applies once to the
         * shipment, not separately to every packet.
         */
        $weightSummary = $this->shipmentWeightCharge(
            totalChargeableWeightKg:
                $totalChargeableWeightKg,
            isSameBranch:
                $isSameBranch,
            settings:
                $settings
        );

        $totalWeightCharge =
            (float) $weightSummary['total'];

        /*
         * Step 5: Allocate the shipment base rate and weight charge
         * proportionally by packet chargeable weight. These allocations
         * are used to apply fragile pricing only to fragile packets.
         */
        $allocationWeights = array_column(
            $packets,
            'chargeable_weight_kg'
        );

        $allocatedBaseRates = $this->allocateAmount(
            amount: $baseRate,
            weights: $allocationWeights
        );

        $allocatedWeightCharges = $this->allocateAmount(
            amount: $totalWeightCharge,
            weights: $allocationWeights
        );

        /*
         * Step 6: Apply the fragile multiplier only to each fragile
         * packet's allocated base-rate and weight-charge portion.
         */
        $fragileMultiplier = max(
            1,
            (float) (
                $settings->fragile_multiplier
                ?? 1.05
            )
        );

        $packetBreakdown = [];
        $totalFragileCharge = 0.0;
        $fragilePacketCount = 0;

        foreach ($packets as $index => $packet) {
            $allocatedBaseRate =
                (float) $allocatedBaseRates[$index];

            $allocatedWeightCharge =
                (float) $allocatedWeightCharges[$index];

            $fragileCalculationBase =
                $allocatedBaseRate +
                $allocatedWeightCharge;

            $isFragile =
                $packet['parcel_type'] === 'fragile';

            if ($isFragile) {
                $fragilePacketCount++;
            }

            $fragileCharge = $isFragile
                ? (
                    $fragileCalculationBase *
                    $fragileMultiplier
                ) - $fragileCalculationBase
                : 0.0;

            $packetSubtotal =
                $allocatedBaseRate +
                $allocatedWeightCharge +
                $fragileCharge;

            $totalFragileCharge +=
                $fragileCharge;

            $packetBreakdown[] = [
                'packet_reference' =>
                    $packet['packet_reference'],

                'source' =>
                    $packet['source'],

                'source_product_index' =>
                    $packet['source_product_index'],

                'source_unit_index' =>
                    $packet['source_unit_index'],

                'product_id' =>
                    $packet['product_id'],

                'name' =>
                    $packet['name'],

                'quantity' => 1,

                'unit_price' =>
                    $packet['unit_price'],

                'parcel_type' =>
                    $packet['parcel_type'],

                'actual_weight_kg' =>
                    round(
                        $packet['actual_weight_kg'],
                        3
                    ),

                'volumetric_weight_kg' =>
                    $packet['volumetric_weight_kg'] !== null
                        ? round(
                            $packet['volumetric_weight_kg'],
                            3
                        )
                        : null,

                'chargeable_weight_kg' =>
                    round(
                        $packet['chargeable_weight_kg'],
                        3
                    ),

                'weight_source' =>
                    $packet['weight_source'],

                'volumetric_applied' =>
                    $packet['volumetric_applied'],

                'volumetric_status' =>
                    $packet['volumetric_status'],

                'volumetric_divisor' =>
                    round(
                        $packet['volumetric_divisor'],
                        2
                    ),

                'dimensions' =>
                    $packet['dimensions'],

                'allocation_share_percentage' =>
                    $totalChargeableWeightKg > 0
                        ? round(
                            (
                                $packet['chargeable_weight_kg'] /
                                $totalChargeableWeightKg
                            ) * 100,
                            4
                        )
                        : 0.0,

                'allocated_base_rate' =>
                    round(
                        $allocatedBaseRate,
                        2
                    ),

                'allocated_weight_charge' =>
                    round(
                        $allocatedWeightCharge,
                        2
                    ),

                'fragile' => [
                    'applied' =>
                        $isFragile,

                    'multiplier' =>
                        $isFragile
                            ? $fragileMultiplier
                            : 1.0,

                    'calculation_base' =>
                        round(
                            $fragileCalculationBase,
                            2
                        ),

                    'total' =>
                        round(
                            $fragileCharge,
                            2
                        ),
                ],

                'packet_subtotal' =>
                    round(
                        $packetSubtotal,
                        2
                    ),
            ];
        }

        $subtotalBeforeFragile =
            $baseRate +
            $totalWeightCharge;

        $subtotalAfterFragile =
            $subtotalBeforeFragile +
            $totalFragileCharge;

        /*
         * Step 7: Extra delivery distance applies once per shipment.
         */
        $distance = $this->extraDeliveryDistanceCharge(
            deliveryDistanceKm:
                $deliveryDistanceKm,
            settings:
                $settings
        );

        $subtotalBeforeSameDay =
            $subtotalAfterFragile +
            $distance['total'];

        /*
         * Step 8: Same-day multiplier applies once to the shipment.
         */
        $sameDay = $this->sameDayCharge(
            serviceCode:
                (string) $serviceType->code,
            calculationBase:
                $subtotalBeforeSameDay,
            isSameBranch:
                $isSameBranch,
            settings:
                $settings
        );

        $subtotalAfterSameDay =
            $subtotalBeforeSameDay +
            $sameDay['total'];

        /*
         * Step 9: Low-packet pickup charge applies once.
         */
        $minimumPacketCharge =
            $this->minimumPacketCharge(
                packetCount:
                    $packetCount,
                settings:
                    $settings
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

            'pricing_model' =>
                'one_shipment_many_individual_packets',

            'packet_input_source' =>
                $packetInputSource,

            'vat' => [
                'inclusive' =>
                    (bool) (
                        $settings->vat_inclusive
                        ?? true
                    ),

                'percentage' =>
                    (float) (
                        $settings->vat_percentage
                        ?? 13
                    ),

                'additional_vat_added' =>
                    false,
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

            'packet_count' =>
                $packetCount,

            'weight_summary' => [
                'total_actual_weight_kg' =>
                    round(
                        $totalActualWeightKg,
                        3
                    ),

                'total_volumetric_weight_kg' =>
                    round(
                        $totalVolumetricWeightKg,
                        3
                    ),

                'total_chargeable_weight_kg' =>
                    round(
                        $totalChargeableWeightKg,
                        3
                    ),

                'included_weight_kg' =>
                    $weightSummary['included_weight_kg'],

                'excess_weight_kg' =>
                    $weightSummary['excess_weight_kg'],

                'additional_weight_applied' =>
                    $weightSummary[
                        'additional_weight_applied'
                    ],

                'route_type' =>
                    $weightSummary['route_type'],

                'rate_per_kg' =>
                    $weightSummary['rate_per_kg'],

                'total_weight_charge' =>
                    $weightSummary['total'],
            ],

            'packets' =>
                $packetBreakdown,

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

                'additional_weight' =>
                    $weightSummary,

                'subtotal_before_fragile' =>
                    round(
                        $subtotalBeforeFragile,
                        2
                    ),

                'fragile' => [
                    'applied' =>
                        $totalFragileCharge > 0,

                    'multiplier' =>
                        $fragileMultiplier,

                    'fragile_packet_count' =>
                        $fragilePacketCount,

                    'non_fragile_packet_count' =>
                        $packetCount -
                        $fragilePacketCount,

                    'total' =>
                        round(
                            $totalFragileCharge,
                            2
                        ),
                ],

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
     * Retrieve active global pricing settings.
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
     * Resolve an active service type.
     */
    private function serviceType(
        string $code
    ): object {
        $normalizedCode = strtolower(
            trim($code)
        );

        if ($normalizedCode === '') {
            throw ValidationException::withMessages([
                'service_type' => [
                    'The service type is required.',
                ],
            ]);
        }

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
     * Retrieve the official route rate and use the reverse
     * direction as a fallback when necessary.
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
     * Calculate physical packets.
     *
     * products[] is expanded so that quantity 3 becomes three
     * individually handled packets.
     */
    private function calculatePackets(
        array $data,
        object $settings
    ): array {
        if (
            isset($data['packets']) &&
            is_array($data['packets']) &&
            count($data['packets']) > 0
        ) {
            return [
                'input_source' => 'packets',
                'packets' => $this->calculateDirectPackets(
                    packets: $data['packets'],
                    settings: $settings
                ),
            ];
        }

        if (
            isset($data['products']) &&
            is_array($data['products']) &&
            count($data['products']) > 0
        ) {
            return [
                'input_source' => 'products_expanded_to_packets',
                'packets' => $this->calculateProductPackets(
                    products: $data['products'],
                    settings: $settings
                ),
            ];
        }

        if (
            isset($data['parcel_weight']) &&
            (float) $data['parcel_weight'] > 0
        ) {
            $legacyPacketCount = max(
                1,
                (int) ($data['packet_count'] ?? 1)
            );

            if ($legacyPacketCount !== 1) {
                throw ValidationException::withMessages([
                    'packets' => [
                        'Individual packets or products are required when packet_count is greater than one.',
                    ],
                ]);
            }

            return [
                'input_source' => 'legacy_single_packet',
                'packets' => [
                    $this->calculatePacket(
                        packet: [
                            'packet_reference' =>
                                'PKT-001',
                            'product_id' =>
                                null,
                            'name' =>
                                $data['name']
                                ?? 'Legacy parcel',
                            'actual_weight_kg' =>
                                $data['parcel_weight'],
                            'parcel_type' =>
                                $data['parcel_type']
                                ?? 'non_fragile',
                            'length_cm' =>
                                $data['parcel_length_cm']
                                ?? $data['length_cm']
                                ?? null,
                            'width_cm' =>
                                $data['parcel_width_cm']
                                ?? $data['width_cm']
                                ?? null,
                            'height_cm' =>
                                $data['parcel_height_cm']
                                ?? $data['height_cm']
                                ?? null,
                        ],
                        settings: $settings,
                        validationPath: 'parcel',
                        packetReference: 'PKT-001',
                        source: 'legacy',
                        sourceProductIndex: null,
                        sourceUnitIndex: null
                    ),
                ],
            ];
        }

        throw ValidationException::withMessages([
            'packets' => [
                'Provide packets, products, or one legacy parcel with parcel_weight.',
            ],
        ]);
    }

    /**
     * Calculate already-separated physical packets.
     */
    private function calculateDirectPackets(
        array $packets,
        object $settings
    ): array {
        $calculatedPackets = [];

        foreach ($packets as $index => $packet) {
            if (!is_array($packet)) {
                throw ValidationException::withMessages([
                    "packets.{$index}" => [
                        'Each packet must be a valid object.',
                    ],
                ]);
            }

            $quantity = max(
                1,
                (int) ($packet['quantity'] ?? 1)
            );

            if ($quantity !== 1) {
                throw ValidationException::withMessages([
                    "packets.{$index}.quantity" => [
                        'Each packets entry must represent exactly one physical packet. Use separate entries for multiple units.',
                    ],
                ]);
            }

            $packetReference = (string) (
                $packet['packet_reference']
                ?? $this->packetReference(
                    count($calculatedPackets) + 1
                )
            );

            $calculatedPackets[] =
                $this->calculatePacket(
                    packet: $packet,
                    settings: $settings,
                    validationPath: "packets.{$index}",
                    packetReference: $packetReference,
                    source: 'packets',
                    sourceProductIndex: null,
                    sourceUnitIndex: null
                );
        }

        return $calculatedPackets;
    }

    /**
     * Expand every product quantity into separate physical packets.
     */
    private function calculateProductPackets(
        array $products,
        object $settings
    ): array {
        $calculatedPackets = [];

        foreach ($products as $productIndex => $product) {
            if (!is_array($product)) {
                throw ValidationException::withMessages([
                    "products.{$productIndex}" => [
                        'Each product must be a valid object.',
                    ],
                ]);
            }

            $quantity = (int) (
                $product['quantity']
                ?? 1
            );

            if ($quantity < 1) {
                throw ValidationException::withMessages([
                    "products.{$productIndex}.quantity" => [
                        'Product quantity must be at least one.',
                    ],
                ]);
            }

            $unitWeightKg = max(
                0,
                (float) (
                    $product['unit_weight']
                    ?? $product['actual_weight_kg']
                    ?? 0
                )
            );

            if ($unitWeightKg <= 0) {
                throw ValidationException::withMessages([
                    "products.{$productIndex}.unit_weight" => [
                        'The product unit weight must be greater than zero.',
                    ],
                ]);
            }

            for (
                $unitIndex = 1;
                $unitIndex <= $quantity;
                $unitIndex++
            ) {
                $packetNumber =
                    count($calculatedPackets) + 1;

                $packetReference =
                    $this->packetReference(
                        $packetNumber
                    );

                $calculatedPackets[] =
                    $this->calculatePacket(
                        packet: [
                            'packet_reference' =>
                                $packetReference,
                            'product_id' =>
                                $product['product_id']
                                ?? null,
                            'name' =>
                                $quantity > 1
                                    ? sprintf(
                                        '%s - Unit %d',
                                        (string) (
                                            $product['name']
                                            ?? "Product {$productIndex}"
                                        ),
                                        $unitIndex
                                    )
                                    : (
                                        $product['name']
                                        ?? "Product {$productIndex}"
                                    ),
                            'actual_weight_kg' =>
                                $unitWeightKg,
                            'parcel_type' =>
                                $product['parcel_type']
                                ?? 'non_fragile',
                            'unit_price' =>
                                $product['unit_price']
                                ?? null,
                            'length_cm' =>
                                $product['length_cm']
                                ?? $product['parcel_length_cm']
                                ?? null,
                            'width_cm' =>
                                $product['width_cm']
                                ?? $product['parcel_width_cm']
                                ?? null,
                            'height_cm' =>
                                $product['height_cm']
                                ?? $product['parcel_height_cm']
                                ?? null,
                        ],
                        settings: $settings,
                        validationPath:
                            "products.{$productIndex}",
                        packetReference:
                            $packetReference,
                        source:
                            'products',
                        sourceProductIndex:
                            $productIndex,
                        sourceUnitIndex:
                            $unitIndex
                    );
            }
        }

        return $calculatedPackets;
    }

    /**
     * Calculate actual, volumetric and chargeable weight for one packet.
     */
    private function calculatePacket(
        array $packet,
        object $settings,
        string $validationPath,
        string $packetReference,
        string $source,
        ?int $sourceProductIndex,
        ?int $sourceUnitIndex
    ): array {
        $actualWeightKg = max(
            0,
            (float) (
                $packet['actual_weight_kg']
                ?? $packet['parcel_weight']
                ?? $packet['unit_weight']
                ?? 0
            )
        );

        if ($actualWeightKg <= 0) {
            throw ValidationException::withMessages([
                "{$validationPath}.actual_weight_kg" => [
                    'The packet actual weight must be greater than zero.',
                ],
            ]);
        }

        $parcelType = $this->normalizeParcelType(
            (string) (
                $packet['parcel_type']
                ?? 'non_fragile'
            ),
            $validationPath
        );

        $lengthCm = $this->optionalDimension(
            data: $packet,
            possibleKeys: [
                'length_cm',
                'parcel_length_cm',
                'length',
                'parcel_length',
            ]
        );

        $widthCm = $this->optionalDimension(
            data: $packet,
            possibleKeys: [
                'width_cm',
                'parcel_width_cm',
                'width',
                'parcel_width',
            ]
        );

        $heightCm = $this->optionalDimension(
            data: $packet,
            possibleKeys: [
                'height_cm',
                'parcel_height_cm',
                'height',
                'parcel_height',
            ]
        );

        $hasAnyDimension =
            $lengthCm !== null ||
            $widthCm !== null ||
            $heightCm !== null;

        $hasCompleteDimensions =
            $lengthCm !== null &&
            $widthCm !== null &&
            $heightCm !== null;

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

        $volumetricStatus = match (true) {
            $hasCompleteDimensions =>
                'calculated',

            $hasAnyDimension =>
                'incomplete_dimensions',

            default =>
                'not_provided',
        };

        return [
            'packet_reference' =>
                $packetReference,

            'source' =>
                $source,

            'source_product_index' =>
                $sourceProductIndex,

            'source_unit_index' =>
                $sourceUnitIndex,

            'product_id' =>
                isset($packet['product_id'])
                    ? (string) $packet['product_id']
                    : null,

            'name' =>
                isset($packet['name'])
                    ? (string) $packet['name']
                    : null,

            'unit_price' =>
                isset($packet['unit_price'])
                    ? (float) $packet['unit_price']
                    : null,

            'parcel_type' =>
                $parcelType,

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
                    $lengthCm !== null
                        ? round($lengthCm, 2)
                        : null,

                'width_cm' =>
                    $widthCm !== null
                        ? round($widthCm, 2)
                        : null,

                'height_cm' =>
                    $heightCm !== null
                        ? round($heightCm, 2)
                        : null,
            ],
        ];
    }

    /**
     * Normalize accepted parcel-type spellings.
     */
    private function normalizeParcelType(
        string $parcelType,
        string $validationPath
    ): string {
        $normalizedType = strtolower(
            trim($parcelType)
        );

        $normalizedType = str_replace(
            ['-', ' '],
            '_',
            $normalizedType
        );

        if ($normalizedType === 'nonfragile') {
            $normalizedType = 'non_fragile';
        }

        if (
            !in_array(
                $normalizedType,
                [
                    'fragile',
                    'non_fragile',
                ],
                true
            )
        ) {
            throw ValidationException::withMessages([
                "{$validationPath}.parcel_type" => [
                    'Parcel type must be fragile or non_fragile.',
                ],
            ]);
        }

        return $normalizedType;
    }

    /**
     * Find an optional dimension. Missing or empty values return null.
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
     * Create a predictable packet reference.
     */
    private function packetReference(
        int $number
    ): string {
        return 'PKT-' . str_pad(
            (string) $number,
            3,
            '0',
            STR_PAD_LEFT
        );
    }

    /**
     * Apply the included weight once to the complete shipment.
     */
    private function shipmentWeightCharge(
        float $totalChargeableWeightKg,
        bool $isSameBranch,
        object $settings
    ): array {
        $includedWeightKg = max(
            0,
            (float) (
                $settings->included_weight_kg
                ?? 1.5
            )
        );

        $ratePerKg = $isSameBranch
            ? max(
                0,
                (float) (
                    $settings->same_branch_weight_rate
                    ?? 20
                )
            )
            : max(
                0,
                (float) (
                    $settings->other_branch_weight_rate
                    ?? 30
                )
            );

        $excessWeightKg = max(
            0,
            $totalChargeableWeightKg -
            $includedWeightKg
        );

        /*
         * Decimal excess weight is charged directly. No ceil() is used.
         */
        $charge =
            $excessWeightKg *
            $ratePerKg;

        return [
            'total_chargeable_weight_kg' =>
                round(
                    $totalChargeableWeightKg,
                    3
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
     * Allocate an amount proportionally according to packet weights.
     * The final packet receives the remaining amount so the raw
     * allocations always add up to the original amount.
     */
    private function allocateAmount(
        float $amount,
        array $weights
    ): array {
        $weights = array_values(
            array_map(
                static fn ($weight): float =>
                    max(0, (float) $weight),
                $weights
            )
        );

        $weightCount = count($weights);

        if ($weightCount === 0) {
            return [];
        }

        $totalWeight = array_sum($weights);

        if ($totalWeight <= 0) {
            return array_fill(
                0,
                $weightCount,
                0.0
            );
        }

        $allocations = [];
        $remainingAmount = $amount;

        foreach ($weights as $index => $weight) {
            $isLast =
                $index === $weightCount - 1;

            if ($isLast) {
                $allocation =
                    $remainingAmount;
            } else {
                $allocation =
                    $amount *
                    ($weight / $totalWeight);

                $remainingAmount -=
                    $allocation;
            }

            $allocations[] =
                $allocation;
        }

        return $allocations;
    }

    /**
     * Charge for delivery distance beyond the included branch radius.
     */
    private function extraDeliveryDistanceCharge(
        float $deliveryDistanceKm,
        object $settings
    ): array {
        $includedDistanceKm = max(
            0,
            (float) (
                $settings->included_delivery_distance_km
                ?? 5
            )
        );

        $ratePerKm = max(
            0,
            (float) (
                $settings->extra_distance_rate_per_km
                ?? 6
            )
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
     * Apply the same-day multiplier once to the shipment.
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
            (string) (
                $settings->same_day_cutoff_time
                ?? '12:00:00'
            )
        );

        $multiplier = $isSameBranch
            ? max(
                1,
                (float) (
                    $settings->same_branch_sdd_multiplier
                    ?? 1.5
                )
            )
            : max(
                1,
                (float) (
                    $settings->other_branch_sdd_multiplier
                    ?? 2
                )
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
     * Reject same-day requests submitted at or after the cutoff.
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
     * Apply the low-packet pickup charge once.
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
            (int) (
                $settings->minimum_pickup_packets
                ?? 3
            )
        );

        $isApplied =
            $packetCount <
            $minimumPackets;

        $charge = $isApplied
            ? max(
                0,
                (float) (
                    $settings->low_packet_pickup_charge
                    ?? 50
                )
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