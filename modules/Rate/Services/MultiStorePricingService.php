<?php

declare(strict_types=1);

namespace Modules\Rate\Services;

use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use Modules\Rate\Enums\MarketplacePackingMode;

final class MultiStorePricingService
{
    public function __construct(
        private readonly PricingEngineService $pricingEngine
    ) {}

    /**
     * Calculate marketplace pricing without saving checkout_quotes,
     * pricing_quotes, or pricing_quote_items.
     */
    public function calculateOnly(
        array $validated,
        ?int $merchantId = null,
        ?int $marketplaceId = null
    ): array {
        $calculated = $this->calculateMarketplace(
            validated: $validated,
            merchantId: $merchantId,
            marketplaceId: $marketplaceId
        );

        return $calculated['public'];
    }

    /**
     * Calculate the marketplace checkout.
     */
    private function calculateMarketplace(
        array $validated,
        ?int $merchantId = null,
        ?int $marketplaceId = null
    ): array {
        $stores = $validated['stores'] ?? [];

        if (!is_array($stores) || count($stores) === 0) {
            throw ValidationException::withMessages([
                'stores' => [
                    'At least one marketplace store is required.',
                ],
            ]);
        }

        $delivery = $this->resolveDelivery($validated);

        $defaultServiceType = $this->normalizeServiceType(
            $validated['service_type'] ?? 'standard'
        );

        $defaultPaymentType = $this->normalizePaymentType(
            $validated['payment_type'] ?? 'prepaid'
        );

        $storeCalculations = [];

        $productsTotal = 0.0;
        $podTotal = 0.0;
        $deliveryTotal = 0.0;
        $estimatedHours = 0;

        $earliestValidUntil = null;

        foreach ($stores as $storeIndex => $store) {
            if (!is_array($store)) {
                throw ValidationException::withMessages([
                    "stores.{$storeIndex}" => [
                        'Each store must be a valid object.',
                    ],
                ]);
            }

            $calculation = $this->calculateStore(
                store: $store,
                storeIndex: (int) $storeIndex,
                delivery: $delivery,
                defaultServiceType: $defaultServiceType,
                defaultPaymentType: $defaultPaymentType,
                merchantId: $merchantId,
                marketplaceId: $marketplaceId
            );

            $storeCalculations[] = $calculation;

            $productsTotal += (float) $calculation['summary']['parcel_value'];

            $podTotal += (float) $calculation['pod_amount'];

            $deliveryTotal += (float) $calculation['quote']['final_price'];

            $estimatedHours = max(
                $estimatedHours,
                (int) ($calculation['quote']['estimated_hours'] ?? 0)
            );

            $validUntil = $this->toCarbonOrNull(
                $calculation['quote']['valid_until'] ?? null
            );

            if (
                $validUntil !== null &&
                (
                    $earliestValidUntil === null ||
                    $validUntil->lt($earliestValidUntil)
                )
            ) {
                $earliestValidUntil = $validUntil;
            }
        }

        $publicStoreQuotes = array_map(
            fn (array $calculation): array =>
                $this->formatCalculatedStoreQuote($calculation),
            $storeCalculations
        );

        return [
            'public' => [
                'marketplace_id' => $marketplaceId,
                'merchant_id' => $merchantId,

                'external_checkout_id' =>
                    $validated['external_checkout_id'] ?? null,

                'currency' => 'NPR',

                'store_count' => count($publicStoreQuotes),

                'products_total' => round($productsTotal, 2),

                'pod_total' => round($podTotal, 2),

                'delivery_total' => round($deliveryTotal, 2),

                'grand_total' => round(
                    $productsTotal + $deliveryTotal,
                    2
                ),

                'estimated_hours' => $estimatedHours,

                'valid_until' => $earliestValidUntil,

                'store_quotes' => $publicStoreQuotes,
            ],

            'store_calculations' => $storeCalculations,

            'delivery' => $delivery,

            'service_type' => $defaultServiceType,

            'payment_type' => $defaultPaymentType,
        ];
    }

    /**
     * Calculate and persist marketplace checkout.
     */
    public function calculateAndStore(
        array $validated,
        ?int $merchantId = null,
        ?int $marketplaceId = null
    ): array {
        $calculation = $this->calculateMarketplace(
            validated: $validated,
            merchantId: $merchantId,
            marketplaceId: $marketplaceId
        );

        $calculated = $calculation['public'];

        $storeCalculations = $calculation['store_calculations'];

        $delivery = $calculation['delivery'];

        $defaultServiceType = $calculation['service_type'];

        $defaultPaymentType = $calculation['payment_type'];

        return DB::transaction(function () use (
            $validated,
            $merchantId,
            $marketplaceId,
            $calculated,
            $storeCalculations,
            $delivery,
            $defaultServiceType,
            $defaultPaymentType
        ): array {
            $checkoutQuoteNumber = $this->quoteNumber('CQ');

            $serviceTypeId = (int) data_get(
                $storeCalculations,
                '0.quote.service_type.id',
                0
            );

            if ($serviceTypeId <= 0) {
                throw ValidationException::withMessages([
                    'service_type' => [
                        'The pricing engine did not return a valid service type.',
                    ],
                ]);
            }

            $checkoutInsert = [
                'quote_number' => $checkoutQuoteNumber,

                'merchant_id' => $merchantId,

                'delivery_address' =>
                    $delivery['address'],

                'delivery_latitude' =>
                    $delivery['latitude'],

                'delivery_longitude' =>
                    $delivery['longitude'],

                'service_type' =>
                    $defaultServiceType,

                'service_type_id' =>
                    $serviceTypeId,

                'payment_type' =>
                    $defaultPaymentType,

                'products_total' =>
                    $calculated['products_total'],

                'pod_total' =>
                    $calculated['pod_total'],

                'delivery_total' =>
                    $calculated['delivery_total'],

                'grand_total' =>
                    $calculated['grand_total'],

                'currency' =>
                    $calculated['currency'],

                'store_count' =>
                    $calculated['store_count'],

                'status' =>
                    'pending',

                'expires_at' =>
                    $this->toDatabaseDateTime(
                        $calculated['valid_until']
                    ),

                'snapshot_json' => $this->encodeJson([
                    'marketplace_id' =>
                        $marketplaceId,

                    'merchant_id' =>
                        $merchantId,

                    'external_checkout_id' =>
                        $validated['external_checkout_id'] ?? null,

                    'delivery' =>
                        $delivery,

                    'service_type' =>
                        $defaultServiceType,

                    'payment_type' =>
                        $defaultPaymentType,

                    'products_total' =>
                        $calculated['products_total'],

                    'pod_total' =>
                        $calculated['pod_total'],

                    'delivery_total' =>
                        $calculated['delivery_total'],

                    'grand_total' =>
                        $calculated['grand_total'],

                    'store_count' =>
                        $calculated['store_count'],

                    'store_quotes' =>
                        $calculated['store_quotes'],
                ]),

                'created_at' =>
                    now(),

                'updated_at' =>
                    now(),
            ];

            $checkoutInsert = $this->withColumnIfExists(
                'checkout_quotes',
                $checkoutInsert,
                'marketplace_id',
                $marketplaceId
            );

            $checkoutInsert = $this->withColumnIfExists(
                'checkout_quotes',
                $checkoutInsert,
                'external_checkout_id',
                $validated['external_checkout_id'] ?? null
            );

            $checkoutQuoteId = DB::table('checkout_quotes')
                ->insertGetId($checkoutInsert);

            $savedStoreQuotes = [];

            foreach ($storeCalculations as $calculation) {
                $savedStoreQuotes[] = $this->saveStoreQuote(
                    checkoutQuoteId: (int) $checkoutQuoteId,
                    checkoutQuoteNumber: $checkoutQuoteNumber,
                    calculation: $calculation,
                    delivery: $delivery,
                    merchantId: $merchantId,
                    marketplaceId: $marketplaceId
                );
            }

            return [
                'checkout_quote_id' =>
                    (int) $checkoutQuoteId,

                'checkout_quote_number' =>
                    $checkoutQuoteNumber,

                'external_checkout_id' =>
                    $validated['external_checkout_id'] ?? null,

                'marketplace_id' =>
                    $marketplaceId,

                'merchant_id' =>
                    $merchantId,

                'currency' =>
                    $calculated['currency'],

                'store_count' =>
                    count($savedStoreQuotes),

                'products_total' =>
                    $calculated['products_total'],

                'pod_total' =>
                    $calculated['pod_total'],

                'delivery_total' =>
                    $calculated['delivery_total'],

                'grand_total' =>
                    $calculated['grand_total'],

                'estimated_hours' =>
                    $calculated['estimated_hours'],

                'valid_until' =>
                    $calculated['valid_until'],

                'store_quotes' =>
                    $savedStoreQuotes,
            ];
        }, 3);
    }

    /**
     * Calculate one marketplace store.
     *
     * IMPORTANT:
     * The marketplace products are the source of truth.
     */
    private function calculateStore(
        array $store,
        int $storeIndex,
        array $delivery,
        string $defaultServiceType,
        string $defaultPaymentType,
        ?int $merchantId,
        ?int $marketplaceId
    ): array {
        $products = $this->resolveProducts($store);

        $packets = $this->resolvePackets($store);

        /*
         * Resolve packing policy.
         */
        $packingMode = MarketplacePackingMode::resolve(
            $store['packing_policy']
                ?? MarketplacePackingMode::configured()->value
        );

        /*
         * Validate products before calculating anything.
         *
         * This is important because the marketplace must not be
         * allowed to manipulate parcel weight/value independently
         * from the products.
         */
        $this->validateMarketplaceProducts(
            products: $products,
            storeIndex: $storeIndex
        );

        /*
         * Validate explicitly supplied packets.
         */
        $this->validateMarketplacePackets(
            packets: $packets,
            storeIndex: $storeIndex
        );

        /*
         * Calculate actual weight/value/dimensions.
         */
        $summary = $this->storeSummary(
            store: $store,
            products: $products,
            packets: $packets,
            packingMode: $packingMode,
            storeIndex: $storeIndex
        );

        /*
         * Build the exact physical input that the PricingEngine
         * should use.
         */
        $pricingInput = $this->buildPricingInput(
            store: $store,
            storeIndex: $storeIndex,
            products: $products,
            packets: $packets,
            summary: $summary,
            packingMode: $packingMode
        );

        $serviceType = $this->normalizeServiceType(
            $store['service_type'] ?? $defaultServiceType
        );

        $paymentType = $this->normalizePaymentType(
            $store['payment_type'] ?? $defaultPaymentType
        );

        $podAmount = 0.0;

        if ($paymentType === 'pod') {
            $podAmount = isset($store['pod_amount'])
                ? max(0, (float) $store['pod_amount'])
                : (float) $summary['parcel_value'];
        }

        /*
         * IMPORTANT:
         *
         * parcel_weight comes from the calculated product data.
         *
         * parcel_dimension also comes from product data.
         *
         * Client supplied parcel_weight is NOT trusted when products
         * are present.
         */
        $payload = [
            'store_id' =>
                isset($store['store_id'])
                    ? (int) $store['store_id']
                    : null,

            'pickup_address' =>
                $store['pickup_address'] ?? null,

            'pickup_latitude' =>
                $store['pickup_latitude'] ?? null,

            'pickup_longitude' =>
                $store['pickup_longitude'] ?? null,

            'delivery_address' =>
                $delivery['address'],

            'delivery_latitude' =>
                $delivery['latitude'],

            'delivery_longitude' =>
                $delivery['longitude'],

            /*
             * Physical marketplace products.
             */
            'products' =>
                $pricingInput['products'],

            /*
             * Physical packets.
             */
            'packets' =>
                $pricingInput['packets'],

            /*
             * Calculated values.
             */
            'packet_count' =>
                $summary['packet_count'],

            'parcel_weight' =>
                $summary['parcel_weight'],

            'parcel_value' =>
                $summary['parcel_value'],

            'parcel_type' =>
                $summary['parcel_type'],

            /*
             * Dimensions are explicitly exposed as well.
             */
            'dimensions' =>
                $summary['dimensions'],

            'payment_type' =>
                $paymentType,

            'pod_amount' =>
                $podAmount,

            'service_type' =>
                $serviceType,

            /*
             * Marketplace uses configured transfer route pricing.
             */
            'base_rate_mode' =>
                config(
                    'marketplace.base_rate_mode',
                    'configured_transfer_route'
                ),
        ];

        /*
         * Existing PricingEngineService is still the authority
         * for the actual delivery price.
         */
        $quote = $this->pricingEngine->calculate(
            $payload,
            $merchantId
        );

        $this->validateQuote(
            $quote,
            $storeIndex
        );

        return [
            'store_index' =>
                $storeIndex,

            'store' =>
                $store,

            'store_id' =>
                $payload['store_id'],

            'external_store_id' =>
                $store['external_store_id'] ?? null,

            'marketplace_id' =>
                $marketplaceId,

            'merchant_id' =>
                $merchantId,

            'input_mode' => match (true) {
                count($packets) > 0 =>
                    'packets',

                count($products) > 0 =>
                    'products',

                default =>
                    'legacy_single_parcel',
            },

            'packing_policy' =>
                $packingMode->value,

            'products' =>
                $products,

            'packets' =>
                $packets,

            'pricing_products' =>
                $pricingInput['products'],

            'pricing_packets' =>
                $pricingInput['packets'],

            'summary' =>
                $summary,

            'payment_type' =>
                $paymentType,

            'service_type' =>
                $serviceType,

            'pod_amount' =>
                $podAmount,

            'quote' =>
                $quote,
        ];
    }

    /**
     * Validate marketplace products.
     */
    private function validateMarketplaceProducts(
        array $products,
        int $storeIndex
    ): void {
        if (count($products) === 0) {
            return;
        }

        foreach ($products as $productIndex => $product) {
            $prefix = "stores.{$storeIndex}.products.{$productIndex}";

            $quantity = (int) ($product['quantity'] ?? 0);

            if ($quantity <= 0) {
                throw ValidationException::withMessages([
                    "{$prefix}.quantity" => [
                        'Product quantity must be greater than zero.',
                    ],
                ]);
            }

            $unitWeight = (float) ($product['unit_weight'] ?? 0);

            if ($unitWeight <= 0) {
                throw ValidationException::withMessages([
                    "{$prefix}.unit_weight" => [
                        'Product unit weight must be greater than zero.',
                    ],
                ]);
            }

            $unitPrice = (float) ($product['unit_price'] ?? 0);

            if ($unitPrice < 0) {
                throw ValidationException::withMessages([
                    "{$prefix}.unit_price" => [
                        'Product unit price cannot be negative.',
                    ],
                ]);
            }

            /*
             * Maximum weight per product.
             *
             * Keep this configurable so the marketplace rule and
             * public pricing rule do not need to be duplicated here.
             */
            $maxUnitWeight = (float) config(
                'marketplace.max_product_weight_kg',
                100
            );

            if ($unitWeight > $maxUnitWeight) {
                throw ValidationException::withMessages([
                    "{$prefix}.unit_weight" => [
                        "Product unit weight cannot exceed {$maxUnitWeight} kg.",
                    ],
                ]);
            }

            /*
             * Dimensions are REQUIRED for marketplace products.
             */
            $dimension = $product['parcel_dimension'] ?? null;

            if (!is_array($dimension)) {
                throw ValidationException::withMessages([
                    "{$prefix}.parcel_dimension" => [
                        'Product parcel_dimension is required.',
                    ],
                ]);
            }

            $normalized = $this->normalizeDimensions(
                $dimension,
                "{$prefix}.parcel_dimension"
            );

            /*
             * Maximum individual dimension.
             */
            $maxDimension = (float) config(
                'marketplace.max_dimension_cm',
                200
            );

            foreach (
                [
                    'length' => $normalized['length_cm'],
                    'width' => $normalized['width_cm'],
                    'height' => $normalized['height_cm'],
                ] as $name => $value
            ) {
                if ($value > $maxDimension) {
                    throw ValidationException::withMessages([
                        "{$prefix}.parcel_dimension.{$name}" => [
                            "Product {$name} cannot exceed {$maxDimension} cm.",
                        ],
                    ]);
                }
            }

            /*
             * Maximum product volume.
             */
            $maxVolume = (float) config(
                'marketplace.max_volume_cm3',
                200 * 200 * 200
            );

            $volume =
                $normalized['length_cm']
                * $normalized['width_cm']
                * $normalized['height_cm'];

            if ($volume > $maxVolume) {
                throw ValidationException::withMessages([
                    "{$prefix}.parcel_dimension" => [
                        'Product parcel dimensions exceed the maximum allowed volume.',
                    ],
                ]);
            }
        }
    }

    /**
     * Validate explicit packets.
     */
    private function validateMarketplacePackets(
        array $packets,
        int $storeIndex
    ): void {
        foreach ($packets as $packetIndex => $packet) {
            $prefix = "stores.{$storeIndex}.packets.{$packetIndex}";

            $weight = (float) (
                $packet['actual_weight_kg']
                ?? $packet['actual_weight']
                ?? 0
            );

            if ($weight <= 0) {
                throw ValidationException::withMessages([
                    "{$prefix}.actual_weight_kg" => [
                        'Packet actual weight must be greater than zero.',
                    ],
                ]);
            }

            $maxWeight = (float) config(
                'marketplace.max_packet_weight_kg',
                100
            );

            if ($weight > $maxWeight) {
                throw ValidationException::withMessages([
                    "{$prefix}.actual_weight_kg" => [
                        "Packet weight cannot exceed {$maxWeight} kg.",
                    ],
                ]);
            }

            if (isset($packet['parcel_dimension'])) {
                $this->normalizeDimensions(
                    $packet['parcel_dimension'],
                    "{$prefix}.parcel_dimension"
                );
            }
        }
    }

    /**
     * Calculate the actual store summary.
     *
     * Products are authoritative.
     */
    private function storeSummary(
        array $store,
        array $products,
        array $packets,
        MarketplacePackingMode $packingMode,
        int $storeIndex
    ): array {
        if (
            count($products) > 0 &&
            count($packets) > 0
        ) {
            throw ValidationException::withMessages([
                "stores.{$storeIndex}" => [
                    'A store cannot provide products and packets together.',
                ],
            ]);
        }

        if (
            $packingMode->isExplicitPackets() &&
            count($packets) === 0
        ) {
            throw ValidationException::withMessages([
                "stores.{$storeIndex}.packets" => [
                    'The packets array is required when explicit_packets mode is active.',
                ],
            ]);
        }

        /*
         * ---------------------------------------------------------------
         * PRODUCTS
         * ---------------------------------------------------------------
         */
        if (count($products) > 0) {
            $weight = 0.0;

            $value = 0.0;

            $productUnitCount = 0;

            $fragile = false;

            $dimensions = [];

            foreach ($products as $productIndex => $item) {
                $quantity = max(
                    1,
                    (int) ($item['quantity'] ?? 1)
                );

                $unitWeight = max(
                    0,
                    (float) ($item['unit_weight'] ?? 0)
                );

                $unitPrice = max(
                    0,
                    (float) ($item['unit_price'] ?? 0)
                );

                $parcelType = $this->normalizeParcelType(
                    $item['parcel_type'] ?? 'non_fragile'
                );

                /*
                 * Actual total weight.
                 */
                $weight +=
                    $unitWeight * $quantity;

                /*
                 * Actual declared product value.
                 */
                $value +=
                    $unitPrice * $quantity;

                $productUnitCount +=
                    $quantity;

                if ($parcelType === 'fragile') {
                    $fragile = true;
                }

                /*
                 * Normalize dimensions and retain them.
                 */
                $dimension = $this->normalizeDimensions(
                    $item['parcel_dimension'] ?? [],
                    "stores.{$storeIndex}.products.{$productIndex}.parcel_dimension"
                );

                $dimensions[] = [
                    'product_index' =>
                        $productIndex,

                    'product_id' =>
                        $item['product_id'] ?? null,

                    'quantity' =>
                        $quantity,

                    'length_cm' =>
                        $dimension['length_cm'],

                    'width_cm' =>
                        $dimension['width_cm'],

                    'height_cm' =>
                        $dimension['height_cm'],

                    'volume_cm3' =>
                        $dimension['volume_cm3'],
                ];
            }

            /*
             * Validate total store weight.
             */
            $this->validateTotalWeight(
                weight: $weight,
                storeIndex: $storeIndex
            );

            $packetCount = match ($packingMode) {
                MarketplacePackingMode::SinglePerStore =>
                    1,

                MarketplacePackingMode::PerProductQuantity =>
                    max(1, $productUnitCount),

                MarketplacePackingMode::ExplicitPackets =>
                    throw ValidationException::withMessages([
                        "stores.{$storeIndex}.products" => [
                            'Products cannot be used while explicit_packets mode is active.',
                        ],
                    ]),
            };

            /*
             * Calculate physical dimensions for the resulting package.
             *
             * For one product this is exactly the product dimension.
             *
             * For multiple products, the largest L/W/H is used as the
             * minimum bounding dimension. This prevents dimensions from
             * disappearing when products are combined into one store packet.
             */
            $combinedDimensions = $this->combineDimensions(
                $dimensions
            );

            return [
                'packet_count' =>
                    $packetCount,

                'product_unit_count' =>
                    $productUnitCount,

                'parcel_weight' =>
                    round($weight, 3),

                'parcel_value' =>
                    round($value, 2),

                'parcel_type' =>
                    $fragile
                        ? 'fragile'
                        : 'non_fragile',

                'dimensions' =>
                    $combinedDimensions,

                'product_dimensions' =>
                    $dimensions,
            ];
        }

        /*
         * ---------------------------------------------------------------
         * EXPLICIT PACKETS
         * ---------------------------------------------------------------
         */
        if (count($packets) > 0) {
            $weight = 0.0;

            $value = 0.0;

            $fragile = false;

            $dimensions = [];

            foreach ($packets as $packetIndex => $packet) {
                $actualWeight = max(
                    0,
                    (float) (
                        $packet['actual_weight_kg']
                        ?? $packet['actual_weight']
                        ?? 0
                    )
                );

                $declaredValue = max(
                    0,
                    (float) (
                        $packet['declared_value']
                        ?? $packet['unit_price']
                        ?? 0
                    )
                );

                $weight += $actualWeight;

                $value += $declaredValue;

                if (
                    $this->normalizeParcelType(
                        $packet['parcel_type'] ?? 'non_fragile'
                    ) === 'fragile'
                ) {
                    $fragile = true;
                }

                if (isset($packet['parcel_dimension'])) {
                    $dimensions[] = $this->normalizeDimensions(
                        $packet['parcel_dimension'],
                        "stores.{$storeIndex}.packets.{$packetIndex}.parcel_dimension"
                    );
                } else {
                    /*
                     * Support flat packet dimensions too.
                     */
                    if (
                        isset($packet['length_cm']) &&
                        isset($packet['width_cm']) &&
                        isset($packet['height_cm'])
                    ) {
                        $dimensions[] = $this->normalizeDimensions(
                            [
                                'length' =>
                                    $packet['length_cm'],

                                'width' =>
                                    $packet['width_cm'],

                                'height' =>
                                    $packet['height_cm'],

                                'unit' =>
                                    'cm',
                            ],
                            "stores.{$storeIndex}.packets.{$packetIndex}"
                        );
                    }
                }
            }

            $this->validateTotalWeight(
                weight: $weight,
                storeIndex: $storeIndex
            );

            $packetCount =
                $packingMode->isSinglePerStore()
                    ? 1
                    : count($packets);

            return [
                'packet_count' =>
                    max(1, $packetCount),

                'product_unit_count' =>
                    count($packets),

                'parcel_weight' =>
                    round($weight, 3),

                'parcel_value' =>
                    round($value, 2),

                'parcel_type' =>
                    $fragile
                        ? 'fragile'
                        : 'non_fragile',

                'dimensions' =>
                    $this->combineDimensions($dimensions),

                'product_dimensions' =>
                    $dimensions,
            ];
        }

        /*
         * ---------------------------------------------------------------
         * LEGACY SINGLE PARCEL
         * ---------------------------------------------------------------
         */
        $parcelWeight = max(
            0,
            (float) (
                $store['parcel_weight'] ?? 0
            )
        );

        if ($parcelWeight <= 0) {
            throw ValidationException::withMessages([
                "stores.{$storeIndex}.parcel_weight" => [
                    'Each store must provide products, packets, or parcel_weight.',
                ],
            ]);
        }

        $this->validateTotalWeight(
            weight: $parcelWeight,
            storeIndex: $storeIndex
        );

        $dimensions = null;

        if (
            isset($store['package_length_cm']) &&
            isset($store['package_width_cm']) &&
            isset($store['package_height_cm'])
        ) {
            $dimensions = $this->normalizeDimensions(
                [
                    'length' =>
                        $store['package_length_cm'],

                    'width' =>
                        $store['package_width_cm'],

                    'height' =>
                        $store['package_height_cm'],

                    'unit' =>
                        'cm',
                ],
                "stores.{$storeIndex}.package_dimensions"
            );
        }

        return [
            'packet_count' =>
                max(
                    1,
                    (int) (
                        $store['packet_count'] ?? 1
                    )
                ),

            'product_unit_count' =>
                1,

            'parcel_weight' =>
                round($parcelWeight, 3),

            'parcel_value' =>
                round(
                    max(
                        0,
                        (float) (
                            $store['parcel_value'] ?? 0
                        )
                    ),
                    2
                ),

            'parcel_type' =>
                $this->normalizeParcelType(
                    $store['parcel_type'] ?? 'non_fragile'
                ),

            'dimensions' =>
                $dimensions,

            'product_dimensions' =>
                [],
        ];
    }

    /**
     * Build physical pricing input.
     */
    private function buildPricingInput(
        array $store,
        int $storeIndex,
        array $products,
        array $packets,
        array $summary,
        MarketplacePackingMode $packingMode
    ): array {
        /*
         * ---------------------------------------------------------------
         * SINGLE PER STORE
         * ---------------------------------------------------------------
         *
         * The marketplace products are combined into ONE physical packet.
         */
        if ($packingMode->isSinglePerStore()) {
            $dimensions = $summary['dimensions'];

            return [
                /*
                 * Keep products out of the pricing engine because the
                 * store is being priced as one physical parcel.
                 */
                'products' => [],

                'packets' => [
                    [
                        'packet_reference' =>
                            sprintf(
                                'STORE-%d-PKT-001',
                                $storeIndex + 1
                            ),

                        'name' =>
                            sprintf(
                                'Combined package for %s',
                                (string) (
                                    $store['external_store_id']
                                    ?? $store['store_id']
                                    ?? $storeIndex + 1
                                )
                            ),

                        'quantity' =>
                            1,

                        'actual_weight_kg' =>
                            $summary['parcel_weight'],

                        /*
                         * Also expose actual_weight for engines/legacy
                         * code that use this key.
                         */
                        'actual_weight' =>
                            $summary['parcel_weight'],

                        'declared_value' =>
                            $summary['parcel_value'],

                        'unit_price' =>
                            $summary['parcel_value'],

                        'parcel_type' =>
                            $summary['parcel_type'],

                        /*
                         * IMPORTANT:
                         * Dimensions now come from the marketplace
                         * product's parcel_dimension.
                         */
                        'length_cm' =>
                            $dimensions['length_cm'] ?? null,

                        'width_cm' =>
                            $dimensions['width_cm'] ?? null,

                        'height_cm' =>
                            $dimensions['height_cm'] ?? null,

                        /*
                         * Keep the nested representation as well.
                         */
                        'parcel_dimension' =>
                            $dimensions
                                ? [
                                    'length' =>
                                        $dimensions['length_cm'],

                                    'width' =>
                                        $dimensions['width_cm'],

                                    'height' =>
                                        $dimensions['height_cm'],

                                    'unit' =>
                                        'cm',
                                ]
                                : null,
                    ],
                ],
            ];
        }

        /*
         * ---------------------------------------------------------------
         * PER PRODUCT QUANTITY
         * ---------------------------------------------------------------
         */
        if ($packingMode->isPerProductQuantity()) {
            if (count($products) > 0) {
                /*
                 * Enrich every product with normalized dimensions so the
                 * PricingEngine can use them directly.
                 */
                $pricingProducts = array_map(
                    function (array $product): array {
                        $dimension = $this->normalizeDimensions(
                            $product['parcel_dimension'] ?? [],
                            'parcel_dimension'
                        );

                        return [
                            ...$product,

                            'length_cm' =>
                                $dimension['length_cm'],

                            'width_cm' =>
                                $dimension['width_cm'],

                            'height_cm' =>
                                $dimension['height_cm'],

                            'parcel_dimension' => [
                                'length' =>
                                    $dimension['length_cm'],

                                'width' =>
                                    $dimension['width_cm'],

                                'height' =>
                                    $dimension['height_cm'],

                                'unit' =>
                                    'cm',
                            ],
                        ];
                    },
                    $products
                );

                return [
                    'products' =>
                        $pricingProducts,

                    'packets' =>
                        [],
                ];
            }

            return [
                'products' =>
                    [],

                'packets' =>
                    $this->normalizePricingPackets($packets),
            ];
        }

        /*
         * ---------------------------------------------------------------
         * EXPLICIT PACKETS
         * ---------------------------------------------------------------
         */
        if (count($packets) === 0) {
            throw ValidationException::withMessages([
                "stores.{$storeIndex}.packets" => [
                    'Packets are required while explicit_packets mode is active.',
                ],
            ]);
        }

        return [
            'products' =>
                [],

            'packets' =>
                $this->normalizePricingPackets($packets),
        ];
    }

    /**
     * Normalize packet input for PricingEngineService.
     */
    private function normalizePricingPackets(
        array $packets
    ): array {
        return array_map(
            function (array $packet): array {
                $actualWeight = (float) (
                    $packet['actual_weight_kg']
                    ?? $packet['actual_weight']
                    ?? 0
                );

                $result = [
                    ...$packet,

                    'actual_weight_kg' =>
                        $actualWeight,

                    'actual_weight' =>
                        $actualWeight,
                ];

                if (isset($packet['parcel_dimension'])) {
                    $dimension = $this->normalizeDimensions(
                        $packet['parcel_dimension'],
                        'parcel_dimension'
                    );

                    $result['length_cm'] =
                        $dimension['length_cm'];

                    $result['width_cm'] =
                        $dimension['width_cm'];

                    $result['height_cm'] =
                        $dimension['height_cm'];

                    $result['parcel_dimension'] = [
                        'length' =>
                            $dimension['length_cm'],

                        'width' =>
                            $dimension['width_cm'],

                        'height' =>
                            $dimension['height_cm'],

                        'unit' =>
                            'cm',
                    ];
                }

                return $result;
            },
            $packets
        );
    }

    /**
     * Normalize dimensions to centimetres.
     */
    private function normalizeDimensions(
        array $dimension,
        string $field
    ): array {
        $length = $dimension['length'] ?? null;

        $width = $dimension['width'] ?? null;

        $height = $dimension['height'] ?? null;

        $unit = strtolower(
            trim(
                (string) (
                    $dimension['unit'] ?? 'cm'
                )
            )
        );

        if (
            $length === null ||
            $width === null ||
            $height === null
        ) {
            throw ValidationException::withMessages([
                $field => [
                    'Length, width and height are required.',
                ],
            ]);
        }

        if (!is_numeric($length)) {
            throw ValidationException::withMessages([
                "{$field}.length" => [
                    'Length must be numeric.',
                ],
            ]);
        }

        if (!is_numeric($width)) {
            throw ValidationException::withMessages([
                "{$field}.width" => [
                    'Width must be numeric.',
                ],
            ]);
        }

        if (!is_numeric($height)) {
            throw ValidationException::withMessages([
                "{$field}.height" => [
                    'Height must be numeric.',
                ],
            ]);
        }

        $length = (float) $length;

        $width = (float) $width;

        $height = (float) $height;

        if (
            $length <= 0 ||
            $width <= 0 ||
            $height <= 0
        ) {
            throw ValidationException::withMessages([
                $field => [
                    'Length, width and height must be greater than zero.',
                ],
            ]);
        }

        /*
         * Convert everything to cm.
         */
        $factor = match ($unit) {
            'mm' => 0.1,

            'cm' => 1.0,

            'm',
            'meter',
            'meters' => 100.0,

            'in',
            'inch',
            'inches' => 2.54,

            default => throw ValidationException::withMessages([
                "{$field}.unit" => [
                    'Dimension unit must be mm, cm, m or in.',
                ],
            ]),
        };

        $lengthCm = round($length * $factor, 3);

        $widthCm = round($width * $factor, 3);

        $heightCm = round($height * $factor, 3);

        return [
            'length_cm' =>
                $lengthCm,

            'width_cm' =>
                $widthCm,

            'height_cm' =>
                $heightCm,

            'volume_cm3' =>
                round(
                    $lengthCm
                    * $widthCm
                    * $heightCm,
                    3
                ),

            'unit' =>
                'cm',
        ];
    }

    /**
     * Combine multiple product dimensions into one physical
     * store package.
     */
    private function combineDimensions(
        array $dimensions
    ): ?array {
        if (count($dimensions) === 0) {
            return null;
        }

        /*
         * For a single product, preserve its exact dimensions.
         */
        if (count($dimensions) === 1) {
            return [
                'length_cm' =>
                    (float) $dimensions[0]['length_cm'],

                'width_cm' =>
                    (float) $dimensions[0]['width_cm'],

                'height_cm' =>
                    (float) $dimensions[0]['height_cm'],

                'volume_cm3' =>
                    (float) $dimensions[0]['volume_cm3'],

                'unit' =>
                    'cm',
            ];
        }

        /*
         * For combined packages use a conservative bounding box:
         *
         * max length × max width × max height
         */
        $length = 0.0;

        $width = 0.0;

        $height = 0.0;

        foreach ($dimensions as $dimension) {
            $length = max(
                $length,
                (float) $dimension['length_cm']
            );

            $width = max(
                $width,
                (float) $dimension['width_cm']
            );

            $height = max(
                $height,
                (float) $dimension['height_cm']
            );
        }

        return [
            'length_cm' =>
                round($length, 3),

            'width_cm' =>
                round($width, 3),

            'height_cm' =>
                round($height, 3),

            'volume_cm3' =>
                round(
                    $length * $width * $height,
                    3
                ),

            'unit' =>
                'cm',
        ];
    }

    /**
     * Validate total physical weight.
     */
    private function validateTotalWeight(
        float $weight,
        int $storeIndex
    ): void {
        if ($weight <= 0) {
            throw ValidationException::withMessages([
                "stores.{$storeIndex}.parcel_weight" => [
                    'Calculated parcel weight must be greater than zero.',
                ],
            ]);
        }

        $maxWeight = (float) config(
            'marketplace.max_store_weight_kg',
            100
        );

        if ($weight > $maxWeight) {
            throw ValidationException::withMessages([
                "stores.{$storeIndex}.products" => [
                    "Total parcel weight cannot exceed {$maxWeight} kg.",
                ],
            ]);
        }
    }

    /**
     * Save store pricing quote.
     */
    private function saveStoreQuote(
        int $checkoutQuoteId,
        string $checkoutQuoteNumber,
        array $calculation,
        array $delivery,
        ?int $merchantId,
        ?int $marketplaceId
    ): array {
        $store = $calculation['store'];

        $summary = $calculation['summary'];

        $quote = $calculation['quote'];

        $storeQuoteNumber = $this->quoteNumber('QT');

        $pricingQuoteInsert = [
            'checkout_quote_id' =>
                $checkoutQuoteId,

            'quote_number' =>
                $storeQuoteNumber,

            'merchant_id' =>
                $merchantId,

            'store_id' =>
                $calculation['store_id'],

            'pickup_branch_id' =>
                (int) data_get(
                    $quote,
                    'pickup_branch.id'
                ),

            'delivery_branch_id' =>
                (int) data_get(
                    $quote,
                    'delivery_branch.id'
                ),

            'pickup_address' =>
                $store['pickup_address'],

            'pickup_latitude' =>
                $store['pickup_latitude'],

            'pickup_longitude' =>
                $store['pickup_longitude'],

            'delivery_address' =>
                $delivery['address'],

            'delivery_latitude' =>
                $delivery['latitude'],

            'delivery_longitude' =>
                $delivery['longitude'],

            'parcel_weight' =>
                $summary['parcel_weight'],

            'parcel_value' =>
                $summary['parcel_value'],

            'parcel_type' =>
                $summary['parcel_type'],

            'payment_type' =>
                $calculation['payment_type'],

            'pod_amount' =>
                $calculation['pod_amount'],

            'service_type' =>
                data_get(
                    $quote,
                    'service_type.code'
                ),

            'service_type_id' =>
                (int) data_get(
                    $quote,
                    'service_type.id'
                ),

            'final_price' =>
                (float) $quote['final_price'],

            'currency' =>
                $quote['currency'] ?? 'NPR',

            'estimated_hours' =>
                isset($quote['estimated_hours'])
                    ? (int) $quote['estimated_hours']
                    : null,

            'sla_due_at' =>
                $this->toDatabaseDateTime(
                    $quote['sla_due_at'] ?? null
                ),

            'expires_at' =>
                $this->toDatabaseDateTime(
                    $quote['valid_until'] ?? null
                ),

            'snapshot_json' =>
                $this->encodeJson([
                    'quote' =>
                        $quote,

                    'products' =>
                        $calculation['products'],

                    'packets' =>
                        $calculation['packets'],

                    'pricing_products' =>
                        $calculation['pricing_products'],

                    'pricing_packets' =>
                        $calculation['pricing_packets'],

                    'summary' =>
                        $summary,

                    'packing_policy' =>
                        $calculation['packing_policy'],
                ]),

            'status' =>
                'pending',

            'created_at' =>
                now(),

            'updated_at' =>
                now(),
        ];

        $pricingQuoteInsert = $this->withColumnIfExists(
            'pricing_quotes',
            $pricingQuoteInsert,
            'marketplace_id',
            $marketplaceId
        );

        $pricingQuoteInsert = $this->withColumnIfExists(
            'pricing_quotes',
            $pricingQuoteInsert,
            'external_store_id',
            $calculation['external_store_id']
        );

        $pricingQuoteInsert = $this->withColumnIfExists(
            'pricing_quotes',
            $pricingQuoteInsert,
            'packet_count',
            $summary['packet_count']
        );

        $pricingQuoteId = DB::table('pricing_quotes')
            ->insertGetId($pricingQuoteInsert);

        $this->savePricingQuoteItems(
            pricingQuoteId: (int) $pricingQuoteId,
            storeId: $calculation['store_id'],
            products: $calculation['products'],
            packets: $calculation['packets']
        );

        return [
            'pricing_quote_id' =>
                (int) $pricingQuoteId,

            'quote_number' =>
                $storeQuoteNumber,

            'checkout_quote_number' =>
                $checkoutQuoteNumber,

            'store_id' =>
                $calculation['store_id'],

            'external_store_id' =>
                $calculation['external_store_id'],

            'input_mode' =>
                $calculation['input_mode'],

            'packet_count' =>
                (int) (
                    $quote['packet_count']
                    ?? $summary['packet_count']
                ),

            'packets' =>
                $quote['packets']
                ?? [],

            'parcel_weight' =>
                $summary['parcel_weight'],

            'parcel_value' =>
                $summary['parcel_value'],

            'parcel_type' =>
                $summary['parcel_type'],

            'payment_type' =>
                $calculation['payment_type'],

            'pod_amount' =>
                $calculation['pod_amount'],

            'pickup_branch' =>
                $quote['pickup_branch'],

            'delivery_branch' =>
                $quote['delivery_branch'],

            'route' =>
                $quote['route'] ?? null,

            'transfer_route' =>
                $quote['transfer_route'] ?? null,

            'service_type' =>
                $quote['service_type'],

            'weight_summary' =>
                $quote['weight_summary'] ?? [],

            'delivery_fee' =>
                (float) $quote['final_price'],

            'currency' =>
                $quote['currency'] ?? 'NPR',

            'estimated_hours' =>
                (int) ($quote['estimated_hours'] ?? 0),

            'sla_due_at' =>
                $this->toCarbonOrNull(
                    $quote['sla_due_at'] ?? null
                ),

            'valid_until' =>
                $this->toCarbonOrNull(
                    $quote['valid_until'] ?? null
                ),

            'breakdown' =>
                $quote['breakdown'],
        ];
    }

    /**
     * Save product/packet quote items.
     */
    private function savePricingQuoteItems(
        int $pricingQuoteId,
        ?int $storeId,
        array $products,
        array $packets
    ): void {
        if (!Schema::hasTable('pricing_quote_items')) {
            return;
        }

        foreach ($products as $item) {
            $quantity = max(
                1,
                (int) ($item['quantity'] ?? 1)
            );

            $unitWeight = max(
                0,
                (float) ($item['unit_weight'] ?? 0)
            );

            $unitPrice = max(
                0,
                (float) ($item['unit_price'] ?? 0)
            );

            $row = [
                'pricing_quote_id' =>
                    $pricingQuoteId,

                'store_id' =>
                    $storeId,

                'product_id' =>
                    $item['product_id'] ?? null,

                'product_name' =>
                    $item['name'] ?? 'Product',

                'sku' =>
                    $item['sku'] ?? null,

                'quantity' =>
                    $quantity,

                'unit_weight' =>
                    $unitWeight,

                'total_weight' =>
                    round(
                        $unitWeight * $quantity,
                        3
                    ),

                'unit_price' =>
                    $unitPrice,

                'total_price' =>
                    round(
                        $unitPrice * $quantity,
                        2
                    ),

                'parcel_type' =>
                    $this->normalizeParcelType(
                        $item['parcel_type']
                        ?? 'non_fragile'
                    ),

                'created_at' =>
                    now(),

                'updated_at' =>
                    now(),
            ];

            DB::table('pricing_quote_items')->insert(
                $this->filterExistingColumns(
                    'pricing_quote_items',
                    $row
                )
            );
        }

        foreach ($packets as $index => $packet) {
            $actualWeight = max(
                0,
                (float) (
                    $packet['actual_weight_kg']
                    ?? $packet['actual_weight']
                    ?? 0
                )
            );

            $declaredValue = max(
                0,
                (float) (
                    $packet['declared_value']
                    ?? $packet['unit_price']
                    ?? 0
                )
            );

            $row = [
                'pricing_quote_id' =>
                    $pricingQuoteId,

                'store_id' =>
                    $storeId,

                'product_id' =>
                    $packet['packet_id'] ?? null,

                'product_name' =>
                    $packet['name']
                    ?? 'Packet ' . ($index + 1),

                'sku' =>
                    $packet['sku'] ?? null,

                'quantity' =>
                    1,

                'unit_weight' =>
                    $actualWeight,

                'total_weight' =>
                    $actualWeight,

                'unit_price' =>
                    $declaredValue,

                'total_price' =>
                    $declaredValue,

                'parcel_type' =>
                    $this->normalizeParcelType(
                        $packet['parcel_type']
                        ?? 'non_fragile'
                    ),

                'created_at' =>
                    now(),

                'updated_at' =>
                    now(),
            ];

            DB::table('pricing_quote_items')->insert(
                $this->filterExistingColumns(
                    'pricing_quote_items',
                    $row
                )
            );
        }
    }

    /**
     * Format public store quote.
     */
    private function formatCalculatedStoreQuote(
        array $calculation
    ): array {
        $quote = $calculation['quote'];

        $summary = $calculation['summary'];

        return [
            'store_index' =>
                $calculation['store_index'],

            'store_id' =>
                $calculation['store_id'],

            'external_store_id' =>
                $calculation['external_store_id'],

            'input_mode' =>
                $calculation['input_mode'],

            'packing_policy' =>
                $calculation['packing_policy'],

            'products' =>
                $calculation['products'],

            'packets' =>
                $quote['packets'] ?? [],

            'product_count' =>
                $this->productCount(
                    $calculation['products'],
                    $calculation['packets']
                ),

            'packet_count' =>
                (int) (
                    $quote['packet_count']
                    ?? $summary['packet_count']
                ),

            'parcel_weight' =>
                $summary['parcel_weight'],

            'parcel_value' =>
                $summary['parcel_value'],

            'parcel_type' =>
                $summary['parcel_type'],

            'dimensions' =>
                $summary['dimensions'],

            'product_dimensions' =>
                $summary['product_dimensions'],

            'payment_type' =>
                $calculation['payment_type'],

            'pod_amount' =>
                $calculation['pod_amount'],

            'pickup_branch' =>
                $quote['pickup_branch'],

            'delivery_branch' =>
                $quote['delivery_branch'],

            'route' =>
                $quote['route'] ?? null,

            'transfer_route' =>
                $quote['transfer_route'] ?? null,

            'service_type' =>
                $quote['service_type'],

            'weight_summary' =>
                $quote['weight_summary'] ?? [],

            'breakdown' =>
                $quote['breakdown'],

            'delivery_charge' =>
                (float) $quote['final_price'],

            'currency' =>
                $quote['currency'] ?? 'NPR',

            'vat' =>
                $quote['vat'] ?? null,

            'estimated_hours' =>
                (int) ($quote['estimated_hours'] ?? 0),

            'sla_due_at' =>
                $this->toCarbonOrNull(
                    $quote['sla_due_at'] ?? null
                ),

            'valid_until' =>
                $this->toCarbonOrNull(
                    $quote['valid_until'] ?? null
                ),
        ];
    }

    /**
     * Resolve delivery information.
     */
    private function resolveDelivery(
        array $validated
    ): array {
        $nested = is_array(
            $validated['delivery'] ?? null
        )
            ? $validated['delivery']
            : [];

        $address =
            $validated['delivery_address']
            ?? $nested['address']
            ?? null;

        $latitude =
            $validated['delivery_latitude']
            ?? $nested['latitude']
            ?? null;

        $longitude =
            $validated['delivery_longitude']
            ?? $nested['longitude']
            ?? null;

        if (
            $address === null ||
            $latitude === null ||
            $longitude === null
        ) {
            throw ValidationException::withMessages([
                'delivery' => [
                    'Delivery address, latitude and longitude are required.',
                ],
            ]);
        }

        return [
            'address' =>
                (string) $address,

            'latitude' =>
                (float) $latitude,

            'longitude' =>
                (float) $longitude,
        ];
    }

    /**
     * Resolve marketplace products.
     */
    private function resolveProducts(
        array $store
    ): array {
        $products =
            $store['products']
            ?? $store['items']
            ?? [];

        if (!is_array($products)) {
            return [];
        }

        return array_values(
            array_map(
                function ($item): array {
                    if (!is_array($item)) {
                        throw ValidationException::withMessages([
                            'products' => [
                                'Each product must be a valid object.',
                            ],
                        ]);
                    }

                    return [
                        ...$item,

                        'parcel_type' =>
                            $this->normalizeParcelType(
                                $item['parcel_type']
                                ?? 'non_fragile'
                            ),
                    ];
                },
                $products
            )
        );
    }

    /**
     * Resolve marketplace packets.
     */
    private function resolvePackets(
        array $store
    ): array {
        $packets =
            $store['packets']
            ?? [];

        if (!is_array($packets)) {
            return [];
        }

        return array_values(
            array_map(
                function ($packet): array {
                    if (!is_array($packet)) {
                        throw ValidationException::withMessages([
                            'packets' => [
                                'Each packet must be a valid object.',
                            ],
                        ]);
                    }

                    return [
                        ...$packet,

                        'quantity' =>
                            1,

                        'parcel_type' =>
                            $this->normalizeParcelType(
                                $packet['parcel_type']
                                ?? 'non_fragile'
                            ),
                    ];
                },
                $packets
            )
        );
    }

    /**
     * Product count.
     */
    private function productCount(
        array $products,
        array $packets
    ): int {
        if (count($products) > 0) {
            return (int) collect($products)->sum(
                fn (array $product): int =>
                    max(
                        0,
                        (int) (
                            $product['quantity']
                            ?? 0
                        )
                    )
            );
        }

        return count($packets);
    }

    /**
     * Validate PricingEngine response.
     */
    private function validateQuote(
        array $quote,
        int $storeIndex
    ): void {
        $required = [
            'pickup_branch.id',
            'delivery_branch.id',
            'service_type.id',
            'service_type.code',
            'breakdown',
            'final_price',
            'valid_until',
        ];

        foreach ($required as $path) {
            if (data_get($quote, $path) === null) {
                throw ValidationException::withMessages([
                    "stores.{$storeIndex}.pricing" => [
                        "Pricing engine response is missing {$path}.",
                    ],
                ]);
            }
        }

        if ((float) $quote['final_price'] < 0) {
            throw ValidationException::withMessages([
                "stores.{$storeIndex}.pricing" => [
                    'Pricing engine returned an invalid negative price.',
                ],
            ]);
        }
    }

    /**
     * Normalize parcel type.
     */
    private function normalizeParcelType(
        mixed $value
    ): string {
        $value = strtolower(
            trim((string) $value)
        );

        return match ($value) {
            'fragile' =>
                'fragile',

            'non-fragile',
            'non fragile',
            'normal',
            'regular' =>
                'non_fragile',

            default =>
                $value !== ''
                    ? $value
                    : 'non_fragile',
        };
    }

    /**
     * Normalize payment type.
     */
    private function normalizePaymentType(
        mixed $value
    ): string {
        $value = strtolower(
            trim((string) $value)
        );

        return match ($value) {
            'pod',
            'cash_on_delivery',
            'payment_on_delivery',
            'cash-on-delivery',
            'payment-on-delivery' =>
                'pod',

            default =>
                $value !== ''
                    ? $value
                    : 'prepaid',
        };
    }

    /**
     * Normalize service type.
     */
    private function normalizeServiceType(
        mixed $value
    ): string {
        $value = strtolower(
            trim((string) $value)
        );

        return match ($value) {
            'same-day',
            'same day',
            'sameday' =>
                'same_day',

            default =>
                $value !== ''
                    ? $value
                    : 'standard',
        };
    }

    /**
     * Convert to Carbon.
     */
    private function toCarbonOrNull(
        mixed $value
    ): ?Carbon {
        if (
            $value === null ||
            $value === ''
        ) {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        return Carbon::parse($value);
    }

    /**
     * Convert to database datetime.
     */
    private function toDatabaseDateTime(
        mixed $value
    ): ?string {
        return $this->toCarbonOrNull(
            $value
        )?->format(
            'Y-m-d H:i:s'
        );
    }

    /**
     * Encode JSON.
     */
    private function encodeJson(
        mixed $value
    ): string {
        try {
            return json_encode(
                $this->serializeDates($value),
                JSON_THROW_ON_ERROR |
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'pricing' => [
                    'The pricing snapshot could not be encoded.',
                ],
            ]);
        }
    }

    /**
     * Serialize dates.
     */
    private function serializeDates(
        mixed $value
    ): mixed {
        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance(
                $value
            )->toIso8601String();
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] =
                    $this->serializeDates($item);
            }
        }

        return $value;
    }

    /**
     * Add a column only when it exists.
     */
    private function withColumnIfExists(
        string $table,
        array $data,
        string $column,
        mixed $value
    ): array {
        if (Schema::hasColumn(
            $table,
            $column
        )) {
            $data[$column] =
                $value;
        }

        return $data;
    }

    /**
     * Filter data to existing columns.
     */
    private function filterExistingColumns(
        string $table,
        array $data
    ): array {
        return collect($data)
            ->filter(
                fn (
                    mixed $value,
                    string $column
                ): bool =>
                    Schema::hasColumn(
                        $table,
                        $column
                    )
            )
            ->all();
    }

    /**
     * Generate quote number.
     */
    private function quoteNumber(
        string $prefix
    ): string {
        return sprintf(
            '%s-%s-%s',
            $prefix,
            now()->format(
                'YmdHisv'
            ),
            Str::upper(
                Str::random(8)
            )
        );
    }
}