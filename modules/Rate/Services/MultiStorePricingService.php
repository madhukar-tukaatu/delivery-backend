<?php

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
     * Build all store calculations once so check-only and stored quotes
     * always use the same pricing result.
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
            fn(array $calculation): array =>
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
                'grand_total' => round($productsTotal + $deliveryTotal, 2),
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
     * Calculate and persist one parent checkout quote and one child
     * pricing quote for each marketplace store.
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
                'delivery_address' => $delivery['address'],
                'delivery_latitude' => $delivery['latitude'],
                'delivery_longitude' => $delivery['longitude'],
                'service_type' => $defaultServiceType,
                'service_type_id' => $serviceTypeId,
                'payment_type' => $defaultPaymentType,
                'products_total' => $calculated['products_total'],
                'pod_total' => $calculated['pod_total'],
                'delivery_total' => $calculated['delivery_total'],
                'grand_total' => $calculated['grand_total'],
                'currency' => $calculated['currency'],
                'store_count' => $calculated['store_count'],
                'status' => 'pending',
                'expires_at' => $this->toDatabaseDateTime(
                    $calculated['valid_until']
                ),
                'snapshot_json' => $this->encodeJson([
                    'marketplace_id' => $marketplaceId,
                    'merchant_id' => $merchantId,
                    'external_checkout_id' =>
                    $validated['external_checkout_id'] ?? null,
                    'delivery' => $delivery,
                    'service_type' => $defaultServiceType,
                    'payment_type' => $defaultPaymentType,
                    'products_total' => $calculated['products_total'],
                    'pod_total' => $calculated['pod_total'],
                    'delivery_total' => $calculated['delivery_total'],
                    'grand_total' => $calculated['grand_total'],
                    'store_count' => $calculated['store_count'],
                    'store_quotes' => $calculated['store_quotes'],
                ]),
                'created_at' => now(),
                'updated_at' => now(),
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
                'checkout_quote_id' => (int) $checkoutQuoteId,
                'checkout_quote_number' => $checkoutQuoteNumber,
                'external_checkout_id' =>
                $validated['external_checkout_id'] ?? null,
                'marketplace_id' => $marketplaceId,
                'merchant_id' => $merchantId,
                'currency' => $calculated['currency'],
                'store_count' => count($savedStoreQuotes),
                'products_total' => $calculated['products_total'],
                'pod_total' => $calculated['pod_total'],
                'delivery_total' => $calculated['delivery_total'],
                'grand_total' => $calculated['grand_total'],
                'estimated_hours' => $calculated['estimated_hours'],
                'valid_until' => $calculated['valid_until'],
                'store_quotes' => $savedStoreQuotes,
            ];
        }, 3);
    }

    // private function calculateStore(
    //     array $store,
    //     int $storeIndex,
    //     array $delivery,
    //     string $defaultServiceType,
    //     string $defaultPaymentType,
    //     ?int $merchantId,
    //     ?int $marketplaceId
    // ): array {
    //     $products = $this->resolveProducts($store);
    //     $packets = $this->resolvePackets($store);
    //     $summary = $this->storeSummary($store, $products, $packets);

    //     $serviceType = $this->normalizeServiceType(
    //         $store['service_type'] ?? $defaultServiceType
    //     );
    //     $paymentType = $this->normalizePaymentType(
    //         $store['payment_type'] ?? $defaultPaymentType
    //     );

    //     $podAmount = 0.0;

    //     if ($paymentType === 'pod') {
    //         $podAmount = isset($store['pod_amount'])
    //             ? max(0, (float) $store['pod_amount'])
    //             : (float) $summary['parcel_value'];
    //     }

    //     $payload = [
    //         'store_id' => isset($store['store_id'])
    //             ? (int) $store['store_id']
    //             : null,
    //         'pickup_address' => $store['pickup_address'] ?? null,
    //         'pickup_latitude' => $store['pickup_latitude'] ?? null,
    //         'pickup_longitude' => $store['pickup_longitude'] ?? null,
    //         'delivery_address' => $delivery['address'],
    //         'delivery_latitude' => $delivery['latitude'],
    //         'delivery_longitude' => $delivery['longitude'],
    //         'products' => $products,
    //         'packets' => $packets,
    //         'packet_count' => $summary['packet_count'],
    //         'parcel_weight' => $summary['parcel_weight'],
    //         'parcel_value' => $summary['parcel_value'],
    //         'parcel_type' => $summary['parcel_type'],
    //         'payment_type' => $paymentType,
    //         'pod_amount' => $podAmount,
    //         'service_type' => $serviceType,

    //         /*
    //          * Marketplace prices use the complete admin-managed transfer
    //          * route and its own base rate.
    //          */
    //         'base_rate_mode' => 'configured_transfer_route',
    //     ];

    //     $quote = $this->pricingEngine->calculate(
    //         $payload,
    //         $merchantId
    //     );

    //     $this->validateQuote($quote, $storeIndex);

    //     return [
    //         'store_index' => $storeIndex,
    //         'store' => $store,
    //         'store_id' => $payload['store_id'],
    //         'external_store_id' =>
    //             $store['external_store_id'] ?? null,
    //         'marketplace_id' => $marketplaceId,
    //         'merchant_id' => $merchantId,
    //         'input_mode' => match (true) {
    //             count($packets) > 0 => 'packets',
    //             count($products) > 0 => 'products',
    //             default => 'legacy_single_parcel',
    //         },
    //         'products' => $products,
    //         'packets' => $packets,
    //         'summary' => $summary,
    //         'payment_type' => $paymentType,
    //         'service_type' => $serviceType,
    //         'pod_amount' => $podAmount,
    //         'quote' => $quote,
    //     ];
    // }

    private function calculateStore(
        array $store,
        int $storeIndex,
        array $delivery,
        string $defaultServiceType,
        string $defaultPaymentType,
        ?int $merchantId,
        ?int $marketplaceId
    ): array {
        $products = $this->resolveProducts(
            $store
        );

        $packets = $this->resolvePackets(
            $store
        );

        /*
     * Use the policy already added by the request.
     * Fall back to the global configuration.
     */
        $packingMode =
            MarketplacePackingMode::resolve(
                $store['packing_policy']
                    ?? MarketplacePackingMode::configured()
                    ->value
            );

        $summary = $this->storeSummary(
            store: $store,
            products: $products,
            packets: $packets,
            packingMode: $packingMode
        );

        $pricingInput =
            $this->buildPricingInput(
                store: $store,
                storeIndex: $storeIndex,
                products: $products,
                packets: $packets,
                summary: $summary,
                packingMode: $packingMode
            );

        $serviceType =
            $this->normalizeServiceType(
                $store['service_type']
                    ?? $defaultServiceType
            );

        $paymentType =
            $this->normalizePaymentType(
                $store['payment_type']
                    ?? $defaultPaymentType
            );

        $podAmount = 0.0;

        if ($paymentType === 'pod') {
            $podAmount =
                isset($store['pod_amount'])
                ? max(
                    0,
                    (float) $store['pod_amount']
                )
                : (float) $summary['parcel_value'];
        }

        $payload = [
            'store_id' =>
            isset($store['store_id'])
                ? (int) $store['store_id']
                : null,

            'pickup_address' =>
            $store['pickup_address']
                ?? null,

            'pickup_latitude' =>
            $store['pickup_latitude']
                ?? null,

            'pickup_longitude' =>
            $store['pickup_longitude']
                ?? null,

            'delivery_address' =>
            $delivery['address'],

            'delivery_latitude' =>
            $delivery['latitude'],

            'delivery_longitude' =>
            $delivery['longitude'],

            /*
         * These two values now depend entirely
         * on the configured packing mode.
         */
            'products' =>
            $pricingInput['products'],

            'packets' =>
            $pricingInput['packets'],

            'packet_count' =>
            $summary['packet_count'],

            'parcel_weight' =>
            $summary['parcel_weight'],

            'parcel_value' =>
            $summary['parcel_value'],

            'parcel_type' =>
            $summary['parcel_type'],

            'payment_type' =>
            $paymentType,

            'pod_amount' =>
            $podAmount,

            'service_type' =>
            $serviceType,

            'base_rate_mode' =>
            config(
                'marketplace.base_rate_mode',
                'configured_transfer_route'
            ),
        ];

        $quote = $this->pricingEngine
            ->calculate(
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
            $store['external_store_id']
                ?? null,

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

            /*
         * Original marketplace products.
         */
            'products' =>
            $products,

            /*
         * Original explicitly supplied packets.
         */
            'packets' =>
            $packets,

            /*
         * Actual data sent to the pricing engine.
         */
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

    // private function storeSummary(
    //     array $store,
    //     array $products,
    //     array $packets
    // ): array {
    //     if (count($products) > 0 && count($packets) > 0) {
    //         throw ValidationException::withMessages([
    //             'stores' => [
    //                 'A store cannot provide products and packets together.',
    //             ],
    //         ]);
    //     }

    //     if (count($products) > 0) {
    //         $weight = 0.0;
    //         $value = 0.0;
    //         $packetCount = 0;
    //         $fragile = false;

    //         foreach ($products as $item) {
    //             $quantity = max(0, (int) ($item['quantity'] ?? 0));
    //             $unitWeight = max(0, (float) ($item['unit_weight'] ?? 0));
    //             $unitPrice = max(0, (float) ($item['unit_price'] ?? 0));
    //             $parcelType = $this->normalizeParcelType(
    //                 $item['parcel_type'] ?? 'non_fragile'
    //             );

    //             $packetCount += $quantity;
    //             $weight += $unitWeight * $quantity;
    //             $value += $unitPrice * $quantity;

    //             if ($parcelType === 'fragile') {
    //                 $fragile = true;
    //             }
    //         }

    //         return [
    //             'packet_count' => max(1, $packetCount),
    //             'parcel_weight' => round($weight, 3),
    //             'parcel_value' => round($value, 2),
    //             'parcel_type' => $fragile
    //                 ? 'fragile'
    //                 : 'non_fragile',
    //         ];
    //     }

    //     if (count($packets) > 0) {
    //         $weight = 0.0;
    //         $value = 0.0;
    //         $fragile = false;

    //         foreach ($packets as $packet) {
    //             $weight += max(
    //                 0,
    //                 (float) ($packet['actual_weight'] ?? 0)
    //             );

    //             $value += max(
    //                 0,
    //                 (float) (
    //                     $packet['declared_value']
    //                     ?? $packet['unit_price']
    //                     ?? 0
    //                 )
    //             );

    //             if (
    //                 $this->normalizeParcelType(
    //                     $packet['parcel_type'] ?? 'non_fragile'
    //                 ) === 'fragile'
    //             ) {
    //                 $fragile = true;
    //             }
    //         }

    //         return [
    //             'packet_count' => count($packets),
    //             'parcel_weight' => round($weight, 3),
    //             'parcel_value' => round($value, 2),
    //             'parcel_type' => $fragile
    //                 ? 'fragile'
    //                 : 'non_fragile',
    //         ];
    //     }

    //     $parcelWeight = max(
    //         0,
    //         (float) ($store['parcel_weight'] ?? 0)
    //     );

    //     if ($parcelWeight <= 0) {
    //         throw ValidationException::withMessages([
    //             'stores' => [
    //                 'Each store must provide products, packets, or parcel_weight.',
    //             ],
    //         ]);
    //     }

    //     return [
    //         'packet_count' => max(
    //             1,
    //             (int) ($store['packet_count'] ?? 1)
    //         ),
    //         'parcel_weight' => round($parcelWeight, 3),
    //         'parcel_value' => round(
    //             max(0, (float) ($store['parcel_value'] ?? 0)),
    //             2
    //         ),
    //         'parcel_type' => $this->normalizeParcelType(
    //             $store['parcel_type'] ?? 'non_fragile'
    //         ),
    //     ];
    // }


    private function storeSummary(
        array $store,
        array $products,
        array $packets,
        MarketplacePackingMode $packingMode
    ): array {
        if (
            count($products) > 0 &&
            count($packets) > 0
        ) {
            throw ValidationException::withMessages([
                'stores' => [
                    'A store cannot provide products and packets together.',
                ],
            ]);
        }

        if (
            $packingMode->isExplicitPackets() &&
            count($packets) === 0
        ) {
            throw ValidationException::withMessages([
                'stores' => [
                    'The packets array is required when explicit_packets mode is active.',
                ],
            ]);
        }

        if (count($products) > 0) {
            $weight = 0.0;
            $value = 0.0;
            $productUnitCount = 0;
            $fragile = false;

            foreach ($products as $item) {
                $quantity = max(
                    1,
                    (int) (
                        $item['quantity']
                        ?? 1
                    )
                );

                $unitWeight = max(
                    0,
                    (float) (
                        $item['unit_weight']
                        ?? 0
                    )
                );

                $unitPrice = max(
                    0,
                    (float) (
                        $item['unit_price']
                        ?? 0
                    )
                );

                $parcelType =
                    $this->normalizeParcelType(
                        $item['parcel_type']
                            ?? 'non_fragile'
                    );

                $weight +=
                    $unitWeight * $quantity;

                $value +=
                    $unitPrice * $quantity;

                $productUnitCount +=
                    $quantity;

                if (
                    $parcelType ===
                    'fragile'
                ) {
                    $fragile = true;
                }
            }

            $packetCount = match ($packingMode) {
                MarketplacePackingMode::SinglePerStore =>
                1,

                MarketplacePackingMode::PerProductQuantity =>
                max(
                    1,
                    $productUnitCount
                ),

                MarketplacePackingMode::ExplicitPackets =>
                throw ValidationException::withMessages([
                    'stores' => [
                        'Products cannot be used while explicit_packets mode is active.',
                    ],
                ]),
            };

            return [
                'packet_count' =>
                $packetCount,

                'product_unit_count' =>
                $productUnitCount,

                'parcel_weight' =>
                round($weight, 3),

                'parcel_value' =>
                round($value, 2),

                /*
             * If any product is fragile, the combined
             * store package is fragile.
             */
                'parcel_type' =>
                $fragile
                    ? 'fragile'
                    : 'non_fragile',
            ];
        }

        if (count($packets) > 0) {
            $weight = 0.0;
            $value = 0.0;
            $fragile = false;

            foreach ($packets as $packet) {
                $weight += max(
                    0,
                    (float) (
                        $packet['actual_weight_kg']
                        ?? $packet['actual_weight']
                        ?? 0
                    )
                );

                $value += max(
                    0,
                    (float) (
                        $packet['declared_value']
                        ?? $packet['unit_price']
                        ?? 0
                    )
                );

                if (
                    $this->normalizeParcelType(
                        $packet['parcel_type']
                            ?? 'non_fragile'
                    ) === 'fragile'
                ) {
                    $fragile = true;
                }
            }

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
            ];
        }

        $parcelWeight = max(
            0,
            (float) (
                $store['parcel_weight']
                ?? 0
            )
        );

        if ($parcelWeight <= 0) {
            throw ValidationException::withMessages([
                'stores' => [
                    'Each store must provide products, packets, or parcel_weight.',
                ],
            ]);
        }

        return [
            'packet_count' =>
            1,

            'product_unit_count' =>
            1,

            'parcel_weight' =>
            round($parcelWeight, 3),

            'parcel_value' =>
            round(
                max(
                    0,
                    (float) (
                        $store['parcel_value']
                        ?? 0
                    )
                ),
                2
            ),

            'parcel_type' =>
            $this->normalizeParcelType(
                $store['parcel_type']
                    ?? 'non_fragile'
            ),
        ];
    }

    private function buildPricingInput(
        array $store,
        int $storeIndex,
        array $products,
        array $packets,
        array $summary,
        MarketplacePackingMode $packingMode
    ): array {
        /*
    |--------------------------------------------------------------------------
    | One combined packet per store
    |--------------------------------------------------------------------------
    */

        if ($packingMode->isSinglePerStore()) {
            return [
                /*
             * Products remain available in the marketplace
             * quote, but are not sent to PricingEngineService.
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

                        'declared_value' =>
                        $summary['parcel_value'],

                        'unit_price' =>
                        $summary['parcel_value'],

                        'parcel_type' =>
                        $summary['parcel_type'],

                        'length_cm' =>
                        $store['package_length_cm']
                            ?? null,

                        'width_cm' =>
                        $store['package_width_cm']
                            ?? null,

                        'height_cm' =>
                        $store['package_height_cm']
                            ?? null,
                    ],
                ],
            ];
        }

        /*
    |--------------------------------------------------------------------------
    | One packet for every product quantity
    |--------------------------------------------------------------------------
    |
    | PricingEngineService receives products and performs
    | its existing product-quantity packet expansion.
    |
    */

        if (
            $packingMode
            ->isPerProductQuantity()
        ) {
            if (count($products) > 0) {
                return [
                    'products' =>
                    $products,

                    'packets' =>
                    [],
                ];
            }

            return [
                'products' =>
                [],

                'packets' =>
                $packets,
            ];
        }

        /*
    |--------------------------------------------------------------------------
    | Explicit packet mode
    |--------------------------------------------------------------------------
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
            $packets,
        ];
    }

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
            'checkout_quote_id' => $checkoutQuoteId,
            'quote_number' => $storeQuoteNumber,
            'merchant_id' => $merchantId,
            'store_id' => $calculation['store_id'],
            'pickup_branch_id' =>
            (int) data_get($quote, 'pickup_branch.id'),
            'delivery_branch_id' =>
            (int) data_get($quote, 'delivery_branch.id'),
            'pickup_address' => $store['pickup_address'],
            'pickup_latitude' => $store['pickup_latitude'],
            'pickup_longitude' => $store['pickup_longitude'],
            'delivery_address' => $delivery['address'],
            'delivery_latitude' => $delivery['latitude'],
            'delivery_longitude' => $delivery['longitude'],
            'parcel_weight' => $summary['parcel_weight'],
            'parcel_value' => $summary['parcel_value'],
            'parcel_type' => $summary['parcel_type'],
            'payment_type' => $calculation['payment_type'],
            'pod_amount' => $calculation['pod_amount'],
            'service_type' => data_get($quote, 'service_type.code'),
            'service_type_id' =>
            (int) data_get($quote, 'service_type.id'),
            'final_price' => (float) $quote['final_price'],
            'currency' => $quote['currency'] ?? 'NPR',
            'estimated_hours' => isset($quote['estimated_hours'])
                ? (int) $quote['estimated_hours']
                : null,
            'sla_due_at' => $this->toDatabaseDateTime(
                $quote['sla_due_at'] ?? null
            ),
            'expires_at' => $this->toDatabaseDateTime(
                $quote['valid_until'] ?? null
            ),
            'snapshot_json' => $this->encodeJson($quote),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
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
            'pricing_quote_id' => (int) $pricingQuoteId,
            'quote_number' => $storeQuoteNumber,
            'checkout_quote_number' => $checkoutQuoteNumber,
            'store_id' => $calculation['store_id'],
            'external_store_id' => $calculation['external_store_id'],
            'input_mode' => $calculation['input_mode'],
            'packet_count' => (int) (
                $quote['packet_count']
                ?? $summary['packet_count']
            ),
            'packets' => $quote['packets'] ?? [],
            'parcel_weight' => $summary['parcel_weight'],
            'parcel_value' => $summary['parcel_value'],
            'parcel_type' => $summary['parcel_type'],
            'payment_type' => $calculation['payment_type'],
            'pod_amount' => $calculation['pod_amount'],
            'pickup_branch' => $quote['pickup_branch'],
            'delivery_branch' => $quote['delivery_branch'],
            'route' => $quote['route'] ?? null,
            'transfer_route' => $quote['transfer_route'] ?? null,
            'service_type' => $quote['service_type'],
            'weight_summary' => $quote['weight_summary'] ?? [],
            'delivery_fee' => (float) $quote['final_price'],
            'currency' => $quote['currency'] ?? 'NPR',
            'estimated_hours' =>
            (int) ($quote['estimated_hours'] ?? 0),
            'sla_due_at' => $this->toCarbonOrNull(
                $quote['sla_due_at'] ?? null
            ),
            'valid_until' => $this->toCarbonOrNull(
                $quote['valid_until'] ?? null
            ),
            'breakdown' => $quote['breakdown'],
        ];
    }

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
            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $unitWeight = max(0, (float) ($item['unit_weight'] ?? 0));
            $unitPrice = max(0, (float) ($item['unit_price'] ?? 0));

            $row = [
                'pricing_quote_id' => $pricingQuoteId,
                'store_id' => $storeId,
                'product_id' => $item['product_id'] ?? null,
                'product_name' => $item['name'] ?? 'Product',
                'sku' => $item['sku'] ?? null,
                'quantity' => $quantity,
                'unit_weight' => $unitWeight,
                'total_weight' => round($unitWeight * $quantity, 3),
                'unit_price' => $unitPrice,
                'total_price' => round($unitPrice * $quantity, 2),
                'parcel_type' => $this->normalizeParcelType(
                    $item['parcel_type'] ?? 'non_fragile'
                ),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            DB::table('pricing_quote_items')->insert(
                $this->filterExistingColumns('pricing_quote_items', $row)
            );
        }

        foreach ($packets as $index => $packet) {
            $actualWeight = max(
                0,
                (float) ($packet['actual_weight'] ?? 0)
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
                'pricing_quote_id' => $pricingQuoteId,
                'store_id' => $storeId,
                'product_id' => $packet['packet_id'] ?? null,
                'product_name' =>
                $packet['name'] ?? 'Packet ' . ($index + 1),
                'sku' => $packet['sku'] ?? null,
                'quantity' => 1,
                'unit_weight' => $actualWeight,
                'total_weight' => $actualWeight,
                'unit_price' => $declaredValue,
                'total_price' => $declaredValue,
                'parcel_type' => $this->normalizeParcelType(
                    $packet['parcel_type'] ?? 'non_fragile'
                ),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            DB::table('pricing_quote_items')->insert(
                $this->filterExistingColumns('pricing_quote_items', $row)
            );
        }
    }

    private function formatCalculatedStoreQuote(
        array $calculation
    ): array {
        $quote = $calculation['quote'];
        $summary = $calculation['summary'];

        return [
            'store_index' => $calculation['store_index'],
            'store_id' => $calculation['store_id'],
            'external_store_id' => $calculation['external_store_id'],
            'input_mode' => $calculation['input_mode'],
            'products' => $calculation['products'],
            'packets' => $quote['packets'] ?? [],
            'product_count' => $this->productCount(
                $calculation['products'],
                $calculation['packets']
            ),
            'packet_count' => (int) (
                $quote['packet_count']
                ?? $summary['packet_count']
            ),
            'parcel_weight' => $summary['parcel_weight'],
            'parcel_value' => $summary['parcel_value'],
            'parcel_type' => $summary['parcel_type'],
            'payment_type' => $calculation['payment_type'],
            'pod_amount' => $calculation['pod_amount'],
            'pickup_branch' => $quote['pickup_branch'],
            'delivery_branch' => $quote['delivery_branch'],
            'route' => $quote['route'] ?? null,
            'transfer_route' => $quote['transfer_route'] ?? null,
            'service_type' => $quote['service_type'],
            'weight_summary' => $quote['weight_summary'] ?? [],
            'breakdown' => $quote['breakdown'],
            'delivery_charge' => (float) $quote['final_price'],
            'currency' => $quote['currency'] ?? 'NPR',
            'vat' => $quote['vat'] ?? null,
            'estimated_hours' =>
            (int) ($quote['estimated_hours'] ?? 0),
            'sla_due_at' => $this->toCarbonOrNull(
                $quote['sla_due_at'] ?? null
            ),
            'valid_until' => $this->toCarbonOrNull(
                $quote['valid_until'] ?? null
            ),
            'packing_policy' =>
            $calculation['packing_policy'],
        ];
    }

    private function resolveDelivery(array $validated): array
    {
        $nested = is_array($validated['delivery'] ?? null)
            ? $validated['delivery']
            : [];

        $address = $validated['delivery_address']
            ?? $nested['address']
            ?? null;
        $latitude = $validated['delivery_latitude']
            ?? $nested['latitude']
            ?? null;
        $longitude = $validated['delivery_longitude']
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
            'address' => (string) $address,
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
        ];
    }

    private function resolveProducts(array $store): array
    {
        $products = $store['products'] ?? $store['items'] ?? [];

        if (!is_array($products)) {
            return [];
        }

        return array_values(array_map(
            function (array $item): array {
                return [
                    ...$item,
                    'parcel_type' => $this->normalizeParcelType(
                        $item['parcel_type'] ?? 'non_fragile'
                    ),
                ];
            },
            $products
        ));
    }

    private function resolvePackets(array $store): array
    {
        $packets = $store['packets'] ?? [];

        if (!is_array($packets)) {
            return [];
        }

        return array_values(array_map(
            function (array $packet): array {
                return [
                    ...$packet,
                    'quantity' => 1,
                    'parcel_type' => $this->normalizeParcelType(
                        $packet['parcel_type'] ?? 'non_fragile'
                    ),
                ];
            },
            $packets
        ));
    }

    private function productCount(
        array $products,
        array $packets
    ): int {
        if (count($products) > 0) {
            return (int) collect($products)->sum(
                fn(array $product): int =>
                max(0, (int) ($product['quantity'] ?? 0))
            );
        }

        return count($packets);
    }

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
    }

    private function normalizeParcelType(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        return match ($value) {
            'fragile' => 'fragile',
            'non-fragile',
            'non fragile',
            'normal',
            'regular' => 'non_fragile',
            default => $value !== '' ? $value : 'non_fragile',
        };
    }

    private function normalizePaymentType(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        return match ($value) {
            'cod',
            'cash_on_delivery',
            'cash-on-delivery' => 'pod',
            default => $value !== '' ? $value : 'prepaid',
        };
    }

    private function normalizeServiceType(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        return match ($value) {
            'same-day',
            'same day',
            'sameday' => 'same_day',
            default => $value !== '' ? $value : 'standard',
        };
    }

    private function toCarbonOrNull(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
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

    private function toDatabaseDateTime(mixed $value): ?string
    {
        return $this->toCarbonOrNull($value)?->format('Y-m-d H:i:s');
    }

    private function encodeJson(mixed $value): string
    {
        try {
            return json_encode(
                $this->serializeDates($value),
                JSON_THROW_ON_ERROR |
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw ValidationException::withMessages([
                'pricing' => [
                    'The pricing snapshot could not be encoded.',
                ],
            ]);
        }
    }

    private function serializeDates(mixed $value): mixed
    {
        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->serializeDates($item);
            }
        }

        return $value;
    }

    private function withColumnIfExists(
        string $table,
        array $data,
        string $column,
        mixed $value
    ): array {
        if (Schema::hasColumn($table, $column)) {
            $data[$column] = $value;
        }

        return $data;
    }

    private function filterExistingColumns(
        string $table,
        array $data
    ): array {
        return collect($data)
            ->filter(
                fn(mixed $value, string $column): bool =>
                Schema::hasColumn($table, $column)
            )
            ->all();
    }

    private function quoteNumber(string $prefix): string
    {
        return sprintf(
            '%s-%s-%s',
            $prefix,
            now()->format('YmdHisv'),
            Str::upper(Str::random(8))
        );
    }
}
