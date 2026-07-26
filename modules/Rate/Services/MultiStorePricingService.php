<?php

namespace Modules\Rate\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

class MultiStorePricingService
{
    public function __construct(
        private readonly PricingEngineService $pricingEngine,
        private readonly TransferLaneResolverService $transferLaneResolver
    ) {
    }

    /**
     * Calculate one-store or multi-store marketplace pricing without saving.
     */
    public function calculateOnly(
        array $validated,
        int $marketplaceId
    ): array {
        $calculation = $this->calculateStores(
            $validated,
            $marketplaceId
        );

        return $this->formatCalculationResponse($calculation);
    }

    /**
     * Save one checkout quote and one child pricing quote per store.
     */
    public function calculateAndStore(
        array $validated,
        int $marketplaceId
    ): array {
        return DB::transaction(function () use (
            $validated,
            $marketplaceId
        ): array {
            $calculation = $this->calculateStores(
                $validated,
                $marketplaceId
            );

            $checkoutQuoteNumber = $this->uniqueQuoteNumber(
                'checkout_quotes',
                'CQ'
            );

            $checkoutRow = [
                'quote_number' => $checkoutQuoteNumber,
                'external_checkout_id' =>
                    $validated['external_checkout_id'] ?? null,
                'merchant_id' => null,
                'marketplace_id' => $marketplaceId,
                'delivery_address' => $calculation['delivery']['address'],
                'delivery_latitude' => $calculation['delivery']['latitude'],
                'delivery_longitude' => $calculation['delivery']['longitude'],
                'service_type' => $calculation['default_service_type'],
                'service_type_id' =>
                    $calculation['default_service_type_id'],
                'payment_type' => $calculation['default_payment_type'],
                'products_total' => $calculation['products_total'],
                'pod_total' => $calculation['pod_total'],
                'delivery_total' => $calculation['delivery_total'],
                'grand_total' => $calculation['grand_total'],
                'currency' => $calculation['currency'],
                'store_count' => count($calculation['stores']),
                'status' => 'pending',
                'expires_at' => $this->toDatabaseDateTime(
                    $calculation['valid_until']
                ),
                'snapshot_json' => $this->encodeJson(
                    $this->serialiseDates($calculation)
                ),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $checkoutQuoteId = DB::table('checkout_quotes')
                ->insertGetId(
                    $this->onlyExistingColumns(
                        'checkout_quotes',
                        $checkoutRow
                    )
                );

            $savedStoreQuotes = [];

            foreach ($calculation['stores'] as $storeCalculation) {
                $savedStoreQuotes[] = $this->saveStoreQuote(
                    checkoutQuoteId: (int) $checkoutQuoteId,
                    checkoutQuoteNumber: $checkoutQuoteNumber,
                    storeCalculation: $storeCalculation,
                    checkoutCalculation: $calculation,
                    marketplaceId: $marketplaceId
                );
            }

            return [
                'checkout_quote_id' => (int) $checkoutQuoteId,
                'checkout_quote_number' => $checkoutQuoteNumber,
                'external_checkout_id' =>
                    $validated['external_checkout_id'] ?? null,
                'marketplace_id' => $marketplaceId,
                'currency' => $calculation['currency'],
                'store_count' => count($savedStoreQuotes),
                'products_total' => $calculation['products_total'],
                'pod_total' => $calculation['pod_total'],
                'delivery_total' => $calculation['delivery_total'],
                'grand_total' => $calculation['grand_total'],
                'estimated_hours' => $calculation['estimated_hours'],
                'valid_until' => $calculation['valid_until'],
                'store_quotes' => $savedStoreQuotes,
                'quote_stored' => true,
                'shipment_created' => false,
            ];
        }, 3);
    }

    private function calculateStores(
        array $validated,
        int $marketplaceId
    ): array {
        $stores = $validated['stores'] ?? [];

        if (!is_array($stores) || count($stores) === 0) {
            throw ValidationException::withMessages([
                'stores' => [
                    'At least one marketplace store is required.',
                ],
            ]);
        }

        $delivery = [
            'address' => (string) $validated['delivery_address'],
            'latitude' => (float) $validated['delivery_latitude'],
            'longitude' => (float) $validated['delivery_longitude'],
        ];

        $defaultServiceType = (string) (
            $validated['service_type'] ?? 'standard'
        );
        $defaultPaymentType = (string) (
            $validated['payment_type'] ?? 'prepaid'
        );

        $storeCalculations = [];
        $productsTotal = 0.0;
        $podTotal = 0.0;
        $deliveryTotal = 0.0;
        $checkoutEstimatedHours = 0;
        $earliestValidUntil = null;
        $defaultServiceTypeId = null;
        $currency = 'NPR';

        foreach ($stores as $index => $store) {
            if (!is_array($store)) {
                throw ValidationException::withMessages([
                    "stores.{$index}" => [
                        'Each marketplace store must be an object.',
                    ],
                ]);
            }

            $summary = $this->storeSummary($store, $index);

            $paymentType = (string) (
                $store['payment_type'] ?? $defaultPaymentType
            );
            $serviceType = (string) (
                $store['service_type'] ?? $defaultServiceType
            );

            $podAmount = $paymentType === 'pod'
                ? (float) (
                    $store['pod_amount'] ?? $summary['parcel_value']
                )
                : 0.0;

            $payload = [
                'store_id' => isset($store['store_id'])
                    ? (int) $store['store_id']
                    : null,
                'pickup_address' => (string) $store['pickup_address'],
                'pickup_latitude' => (float) $store['pickup_latitude'],
                'pickup_longitude' => (float) $store['pickup_longitude'],
                'delivery_address' => $delivery['address'],
                'delivery_latitude' => $delivery['latitude'],
                'delivery_longitude' => $delivery['longitude'],
                'products' => $summary['products'],
                'packets' => $summary['packets'],
                'parcel_weight' => $summary['parcel_weight'],
                'parcel_value' => $summary['parcel_value'],
                'parcel_type' => $summary['parcel_type'],
                'packet_count' => $summary['packet_count'],
                'payment_type' => $paymentType,
                'pod_amount' => $podAmount,
                'service_type' => $serviceType,
            ];

            /*
             * Customer price comes from the direct branch_route_rates record
             * resolved by PricingEngineService.
             */
            try {
                $quote = $this->pricingEngine->calculate(
                    $payload,
                    null
                );
            } catch (ValidationException $exception) {
                $messages = collect($exception->errors())
                    ->flatten()
                    ->map(
                        static fn (mixed $message): string =>
                            (string) $message
                    )
                    ->values()
                    ->all();

                throw ValidationException::withMessages([
                    "stores.{$index}.pricing" =>
                        $messages !== []
                            ? $messages
                            : ['Unable to calculate pricing for this store.'],
                ]);
            }

            $this->validateQuote($quote, $index);

            /*
             * Physical movement comes from branch_transfer_lanes and may be:
             * 0 lanes, 1 direct lane, or multiple lanes through hubs.
             */
            $transferRoute = $this->transferLaneResolver->resolve(
                originBranchId: (int) $quote['pickup_branch']['id'],
                destinationBranchId: (int) $quote['delivery_branch']['id'],
                serviceType: $serviceType
            );

            $pricingEstimatedHours = (int) (
                $quote['estimated_hours'] ?? 0
            );
            $operationalEstimatedHours = (int) (
                $transferRoute['total_estimated_hours'] ?? 0
            );
            $effectiveEstimatedHours = max(
                $pricingEstimatedHours,
                $operationalEstimatedHours
            );

            $quote['pricing_estimated_hours'] =
                $pricingEstimatedHours;
            $quote['operational_estimated_hours'] =
                $operationalEstimatedHours;
            $quote['estimated_hours'] = $effectiveEstimatedHours;
            $quote['sla_due_at'] = now()->addHours(
                $effectiveEstimatedHours
            );
            $quote['transfer_route'] = $transferRoute;

            $defaultServiceTypeId ??=
                (int) $quote['service_type']['id'];

            $currency = (string) (
                $quote['currency'] ?? $currency
            );

            $validUntil = Carbon::parse($quote['valid_until']);

            if (
                $earliestValidUntil === null ||
                $validUntil->lt($earliestValidUntil)
            ) {
                $earliestValidUntil = $validUntil;
            }

            $checkoutEstimatedHours = max(
                $checkoutEstimatedHours,
                $effectiveEstimatedHours
            );

            $productsTotal += $summary['parcel_value'];
            $podTotal += $podAmount;
            $deliveryTotal += (float) $quote['final_price'];

            $storeCalculations[] = [
                'store_index' => (int) $index,
                'store_id' => isset($store['store_id'])
                    ? (int) $store['store_id']
                    : null,
                'external_store_id' =>
                    $store['external_store_id'] ?? null,
                'pickup_address' => (string) $store['pickup_address'],
                'pickup_latitude' =>
                    (float) $store['pickup_latitude'],
                'pickup_longitude' =>
                    (float) $store['pickup_longitude'],
                'products' => $summary['products'],
                'packets_input' => $summary['packets'],
                'input_mode' => $summary['input_mode'],
                'product_count' => $summary['product_count'],
                'packet_count' => (int) (
                    $quote['packet_count'] ?? $summary['packet_count']
                ),
                'parcel_weight' => $summary['parcel_weight'],
                'parcel_value' => $summary['parcel_value'],
                'parcel_type' => $summary['parcel_type'],
                'payment_type' => $paymentType,
                'pod_amount' => round($podAmount, 2),
                'service_type_requested' => $serviceType,
                'transfer_route' => $transferRoute,
                'quote' => $quote,
                'marketplace_id' => $marketplaceId,
            ];
        }

        if (
            $earliestValidUntil === null ||
            $defaultServiceTypeId === null
        ) {
            throw ValidationException::withMessages([
                'pricing' => [
                    'No valid marketplace pricing result was generated.',
                ],
            ]);
        }

        return [
            'delivery' => $delivery,
            'default_service_type' => $defaultServiceType,
            'default_service_type_id' => $defaultServiceTypeId,
            'default_payment_type' => $defaultPaymentType,
            'marketplace_id' => $marketplaceId,
            'currency' => $currency,
            'products_total' => round($productsTotal, 2),
            'pod_total' => round($podTotal, 2),
            'delivery_total' => round($deliveryTotal, 2),
            'grand_total' => round(
                $productsTotal + $deliveryTotal,
                2
            ),
            'estimated_hours' => $checkoutEstimatedHours,
            'valid_until' => $earliestValidUntil,
            'stores' => $storeCalculations,
        ];
    }

    private function storeSummary(array $store, int $index): array
    {
        $products = is_array($store['products'] ?? null)
            ? array_values($store['products'])
            : [];
        $packets = is_array($store['packets'] ?? null)
            ? array_values($store['packets'])
            : [];

        if ($products !== [] && $packets !== []) {
            throw ValidationException::withMessages([
                "stores.{$index}" => [
                    'Products and packets cannot be used together for one store.',
                ],
            ]);
        }

        $inputMode = (string) (
            $store['input_mode'] ?? match (true) {
                $products !== [] => 'products',
                $packets !== [] => 'packets',
                default => 'legacy_single_parcel',
            }
        );

        $parcelWeight = 0.0;
        $parcelValue = 0.0;
        $packetCount = 0;
        $productCount = 0;
        $containsFragile =
            ($store['parcel_type'] ?? null) === 'fragile';

        if ($products !== []) {
            foreach ($products as $key => $product) {
                $quantity = max(
                    1,
                    (int) ($product['quantity'] ?? 1)
                );
                $unitWeight = max(
                    0,
                    (float) ($product['unit_weight'] ?? 0)
                );
                $unitPrice = max(
                    0,
                    (float) ($product['unit_price'] ?? 0)
                );
                $type = $this->normaliseParcelType(
                    $product['parcel_type'] ?? 'non_fragile'
                );

                $products[$key]['parcel_type'] = $type;
                $parcelWeight += $quantity * $unitWeight;
                $parcelValue += $quantity * $unitPrice;
                $packetCount += $quantity;
                $productCount += $quantity;
                $containsFragile = $containsFragile || $type === 'fragile';
            }
        } elseif ($packets !== []) {
            foreach ($packets as $key => $packet) {
                $actualWeight = max(
                    0,
                    (float) ($packet['actual_weight'] ?? 0)
                );
                $declaredValue = max(
                    0,
                    (float) ($packet['declared_value'] ?? 0)
                );
                $type = $this->normaliseParcelType(
                    $packet['parcel_type'] ?? 'non_fragile'
                );

                $packets[$key]['quantity'] = 1;
                $packets[$key]['parcel_type'] = $type;
                $parcelWeight += $actualWeight;
                $parcelValue += $declaredValue;
                $packetCount++;
                $productCount++;
                $containsFragile = $containsFragile || $type === 'fragile';
            }
        } else {
            $parcelWeight = max(
                0,
                (float) ($store['parcel_weight'] ?? 0)
            );
            $parcelValue = max(
                0,
                (float) ($store['parcel_value'] ?? 0)
            );
            $packetCount = max(
                1,
                (int) ($store['packet_count'] ?? 1)
            );
            $productCount = $packetCount;
        }

        if ($parcelWeight <= 0) {
            throw ValidationException::withMessages([
                "stores.{$index}.parcel_weight" => [
                    'The calculated parcel weight must be greater than zero.',
                ],
            ]);
        }

        $parcelType = $containsFragile
            ? 'fragile'
            : 'non_fragile';

        /*
         * Whole-store fragile rule.
         */
        if ($containsFragile) {
            foreach ($products as $key => $product) {
                $products[$key]['parcel_type'] = 'fragile';
            }

            foreach ($packets as $key => $packet) {
                $packets[$key]['parcel_type'] = 'fragile';
                $packets[$key]['is_fragile'] = true;
            }
        }

        return [
            'products' => $products,
            'packets' => $packets,
            'input_mode' => $inputMode,
            'product_count' => $productCount,
            'packet_count' => max(1, $packetCount),
            'parcel_weight' => round($parcelWeight, 3),
            'parcel_value' => round($parcelValue, 2),
            'parcel_type' => $parcelType,
        ];
    }

    private function validateQuote(array $quote, int $storeIndex): void
    {
        $required = [
            'pickup_branch.id',
            'delivery_branch.id',
            'route',
            'service_type.id',
            'service_type.code',
            'breakdown',
            'final_price',
            'currency',
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
                    'Calculated delivery price cannot be negative.',
                ],
            ]);
        }
    }

    private function formatCalculationResponse(array $calculation): array
    {
        return [
            'marketplace_id' => $calculation['marketplace_id'],
            'currency' => $calculation['currency'],
            'store_count' => count($calculation['stores']),
            'products_total' => $calculation['products_total'],
            'pod_total' => $calculation['pod_total'],
            'delivery_total' => $calculation['delivery_total'],
            'grand_total' => $calculation['grand_total'],
            'estimated_hours' => $calculation['estimated_hours'],
            'valid_until' => $calculation['valid_until'],
            'store_quotes' => array_map(
                fn (array $store): array =>
                    $this->formatStoreCalculation($store),
                $calculation['stores']
            ),
            'quote_stored' => false,
            'shipment_created' => false,
        ];
    }

    private function formatStoreCalculation(array $store): array
    {
        $quote = $store['quote'];

        return [
            'store_index' => $store['store_index'],
            'store_id' => $store['store_id'],
            'external_store_id' => $store['external_store_id'],
            'input_mode' => $store['input_mode'],
            'products' => $store['products'],
            'packets' => $quote['packets'] ?? [],
            'product_count' => $store['product_count'],
            'packet_count' => $store['packet_count'],
            'parcel_weight' => $store['parcel_weight'],
            'parcel_value' => $store['parcel_value'],
            'parcel_type' => $store['parcel_type'],
            'payment_type' => $store['payment_type'],
            'pod_amount' => $store['pod_amount'],
            'pickup_branch' => $quote['pickup_branch'],
            'delivery_branch' => $quote['delivery_branch'],
            'customer_pricing_route' => $quote['route'],
            'transfer_route' => $store['transfer_route'],
            'transfer_lane_count' =>
                (int) $store['transfer_route']['lane_count'],
            'service_type' => $quote['service_type'],
            'weight_summary' => $quote['weight_summary'] ?? [],
            'breakdown' => $quote['breakdown'],
            'delivery_charge' => (float) $quote['final_price'],
            'currency' => $quote['currency'] ?? 'NPR',
            'vat' => $quote['vat'] ?? null,
            'pricing_estimated_hours' =>
                (int) ($quote['pricing_estimated_hours'] ?? 0),
            'operational_estimated_hours' =>
                (int) ($quote['operational_estimated_hours'] ?? 0),
            'estimated_hours' =>
                (int) ($quote['estimated_hours'] ?? 0),
            'sla_due_at' => $quote['sla_due_at'] ?? null,
            'valid_until' => $quote['valid_until'],
        ];
    }

    private function saveStoreQuote(
        int $checkoutQuoteId,
        string $checkoutQuoteNumber,
        array $storeCalculation,
        array $checkoutCalculation,
        int $marketplaceId
    ): array {
        $quote = $storeCalculation['quote'];
        $storeQuoteNumber = $this->uniqueQuoteNumber(
            'pricing_quotes',
            'QT'
        );

        $pricingRow = [
            'checkout_quote_id' => $checkoutQuoteId,
            'quote_number' => $storeQuoteNumber,
            'merchant_id' => null,
            'marketplace_id' => $marketplaceId,
            'store_id' => $storeCalculation['store_id'],
            'external_store_id' =>
                $storeCalculation['external_store_id'],
            'pickup_branch_id' =>
                (int) $quote['pickup_branch']['id'],
            'delivery_branch_id' =>
                (int) $quote['delivery_branch']['id'],
            'pickup_address' => $storeCalculation['pickup_address'],
            'pickup_latitude' => $storeCalculation['pickup_latitude'],
            'pickup_longitude' => $storeCalculation['pickup_longitude'],
            'delivery_address' =>
                $checkoutCalculation['delivery']['address'],
            'delivery_latitude' =>
                $checkoutCalculation['delivery']['latitude'],
            'delivery_longitude' =>
                $checkoutCalculation['delivery']['longitude'],
            'parcel_weight' => $storeCalculation['parcel_weight'],
            'parcel_value' => $storeCalculation['parcel_value'],
            'parcel_type' => $storeCalculation['parcel_type'],
            'packet_count' => $storeCalculation['packet_count'],
            'payment_type' => $storeCalculation['payment_type'],
            'pod_amount' => $storeCalculation['pod_amount'],
            'service_type' => $quote['service_type']['code'],
            'service_type_id' =>
                (int) $quote['service_type']['id'],
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
            'snapshot_json' => $this->encodeJson(
                $this->serialiseDates($quote)
            ),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $pricingQuoteId = DB::table('pricing_quotes')
            ->insertGetId(
                $this->onlyExistingColumns(
                    'pricing_quotes',
                    $pricingRow
                )
            );

        $this->saveQuoteItems(
            (int) $pricingQuoteId,
            $storeCalculation
        );

        $this->saveQuoteTransferLanes(
            (int) $pricingQuoteId,
            $storeCalculation['transfer_route']
        );

        return [
            'pricing_quote_id' => (int) $pricingQuoteId,
            'quote_number' => $storeQuoteNumber,
            'checkout_quote_number' => $checkoutQuoteNumber,
            ...$this->formatStoreCalculation($storeCalculation),
        ];
    }

    private function saveQuoteItems(
        int $pricingQuoteId,
        array $storeCalculation
    ): void {
        if (!Schema::hasTable('pricing_quote_items')) {
            return;
        }

        $rows = [];
        $effectiveParcelType = $storeCalculation['parcel_type'];

        foreach ($storeCalculation['products'] as $product) {
            $quantity = max(
                1,
                (int) ($product['quantity'] ?? 1)
            );
            $unitWeight = (float) ($product['unit_weight'] ?? 0);
            $unitPrice = (float) ($product['unit_price'] ?? 0);

            $rows[] = $this->onlyExistingColumns(
                'pricing_quote_items',
                [
                    'pricing_quote_id' => $pricingQuoteId,
                    'store_id' => $storeCalculation['store_id'],
                    'product_id' => $product['product_id'] ?? null,
                    'product_name' => $product['name'] ?? 'Product',
                    'sku' => $product['sku'] ?? null,
                    'quantity' => $quantity,
                    'unit_weight' => $unitWeight,
                    'total_weight' => round(
                        $unitWeight * $quantity,
                        3
                    ),
                    'unit_price' => $unitPrice,
                    'total_price' => round(
                        $unitPrice * $quantity,
                        2
                    ),
                    'parcel_type' => $effectiveParcelType,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        foreach ($storeCalculation['packets_input'] as $packet) {
            $rows[] = $this->onlyExistingColumns(
                'pricing_quote_items',
                [
                    'pricing_quote_id' => $pricingQuoteId,
                    'store_id' => $storeCalculation['store_id'],
                    'product_id' => $packet['packet_id'] ?? null,
                    'product_name' => $packet['name'] ?? 'Packet',
                    'quantity' => 1,
                    'unit_weight' =>
                        (float) ($packet['actual_weight'] ?? 0),
                    'total_weight' =>
                        (float) ($packet['actual_weight'] ?? 0),
                    'unit_price' =>
                        (float) ($packet['declared_value'] ?? 0),
                    'total_price' =>
                        (float) ($packet['declared_value'] ?? 0),
                    'parcel_type' => $effectiveParcelType,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        if ($rows !== []) {
            DB::table('pricing_quote_items')->insert($rows);
        }
    }

    private function saveQuoteTransferLanes(
        int $pricingQuoteId,
        array $transferRoute
    ): void {
        if (!Schema::hasTable('pricing_quote_transfer_lanes')) {
            return;
        }

        $rows = [];

        foreach ($transferRoute['lanes'] ?? [] as $lane) {
            $rows[] = [
                'pricing_quote_id' => $pricingQuoteId,
                'branch_transfer_lane_id' => (int) $lane['lane_id'],
                'sequence_number' => (int) $lane['sequence'],
                'from_branch_id' => (int) $lane['from_branch_id'],
                'to_branch_id' => (int) $lane['to_branch_id'],
                'service_type' =>
                    $lane['service_type'] ?? null,
                'transport_mode' =>
                    $lane['transport_mode'] ?? null,
                'distance_km' => $lane['distance_km'],
                'estimated_hours' =>
                    (int) $lane['estimated_hours'],
                'is_reverse_direction' =>
                    (bool) ($lane['is_reverse_direction'] ?? false),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            $rows = array_map(
                fn (array $row): array =>
                    $this->onlyExistingColumns(
                        'pricing_quote_transfer_lanes',
                        $row
                    ),
                $rows
            );

            DB::table('pricing_quote_transfer_lanes')->insert($rows);
        }
    }

    private function normaliseParcelType(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        return match ($value) {
            'fragile' => 'fragile',
            'non-fragile', 'non fragile', 'normal', 'regular' =>
                'non_fragile',
            default => 'non_fragile',
        };
    }

    private function onlyExistingColumns(
        string $table,
        array $row
    ): array {
        if (!Schema::hasTable($table)) {
            throw ValidationException::withMessages([
                'database' => ["Required table {$table} does not exist."],
            ]);
        }

        $columns = Schema::getColumnListing($table);

        return array_intersect_key(
            $row,
            array_flip($columns)
        );
    }

    private function uniqueQuoteNumber(
        string $table,
        string $prefix
    ): string {
        do {
            $quoteNumber = sprintf(
                '%s-%s-%s',
                $prefix,
                now()->format('YmdHisv'),
                Str::upper(Str::random(8))
            );

            $exists = DB::table($table)
                ->where('quote_number', $quoteNumber)
                ->exists();
        } while ($exists);

        return $quoteNumber;
    }

    private function encodeJson(array $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
        } catch (JsonException $exception) {
            throw ValidationException::withMessages([
                'pricing' => [
                    'The pricing snapshot could not be encoded.',
                ],
            ]);
        }
    }

    private function serialiseDates(mixed $value): mixed
    {
        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toIso8601String();
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->serialiseDates($item);
            }
        }

        return $value;
    }

    private function toDatabaseDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->format('Y-m-d H:i:s');
    }
}
