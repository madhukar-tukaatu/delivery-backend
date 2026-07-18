<?php

namespace Modules\Rate\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MultiStorePricingService
{
    public function __construct(
        private readonly PricingEngineService $pricingEngine
    ) {
    }

    public function calculateAndStore(
        array $data,
        ?int $merchantId
    ): array {
        return DB::transaction(
            function () use (
                $data,
                $merchantId
            ): array {
                $checkoutQuoteNumber =
                    $this->quoteNumber('CQ');

                $storeCalculations = [];

                $productsTotal = 0.0;
                $podTotal = 0.0;
                $deliveryTotal = 0.0;

                foreach ($data['stores'] as $store) {
                    $summary = $this->storeSummary(
                        $store
                    );

                    $storePodAmount =
                        $data['payment_type'] === 'pod'
                            ? $summary['parcel_value']
                            : 0.0;

                    $quote = $this->pricingEngine
                        ->calculate(
                            [
                                'pickup_address' =>
                                    $store[
                                        'pickup_address'
                                    ],

                                'pickup_latitude' =>
                                    $store[
                                        'pickup_latitude'
                                    ],

                                'pickup_longitude' =>
                                    $store[
                                        'pickup_longitude'
                                    ],

                                'delivery_address' =>
                                    $data['delivery'][
                                        'address'
                                    ],

                                'delivery_latitude' =>
                                    $data['delivery'][
                                        'latitude'
                                    ],

                                'delivery_longitude' =>
                                    $data['delivery'][
                                        'longitude'
                                    ],

                                'parcel_weight' =>
                                    $summary[
                                        'parcel_weight'
                                    ],

                                'parcel_value' =>
                                    $summary[
                                        'parcel_value'
                                    ],

                                'parcel_type' =>
                                    $summary[
                                        'parcel_type'
                                    ],

                                'payment_type' =>
                                    $data[
                                        'payment_type'
                                    ],

                                'pod_amount' =>
                                    $storePodAmount,

                                'service_type' =>
                                    $data[
                                        'service_type'
                                    ],
                            ],
                            $merchantId
                        );

                    $storeCalculations[] = [
                        'store' => $store,
                        'summary' => $summary,
                        'pod_amount' =>
                            $storePodAmount,
                        'quote' => $quote,
                    ];

                    $productsTotal +=
                        $summary['parcel_value'];

                    $podTotal +=
                        $storePodAmount;

                    $deliveryTotal +=
                        (float) $quote[
                            'final_price'
                        ];
                }

                $grandTotal = round(
                    $productsTotal +
                    $deliveryTotal,
                    2
                );

                $serviceTypeId =
                    (int) $storeCalculations[0]
                        ['quote']
                        ['service_type']
                        ['id'];

                $validUntil = collect(
                    $storeCalculations
                )->map(
                    fn (array $item) =>
                        $item['quote'][
                            'valid_until'
                        ]
                )->sort()->first();

                $checkoutQuoteId =
                    DB::table(
                        'checkout_quotes'
                    )->insertGetId([
                        'quote_number' =>
                            $checkoutQuoteNumber,

                        'merchant_id' =>
                            $merchantId,

                        'delivery_address' =>
                            $data['delivery'][
                                'address'
                            ],

                        'delivery_latitude' =>
                            $data['delivery'][
                                'latitude'
                            ],

                        'delivery_longitude' =>
                            $data['delivery'][
                                'longitude'
                            ],

                        'service_type' =>
                            $data['service_type'],

                        'service_type_id' =>
                            $serviceTypeId,

                        'payment_type' =>
                            $data['payment_type'],

                        'products_total' =>
                            round(
                                $productsTotal,
                                2
                            ),

                        'pod_total' =>
                            round($podTotal, 2),

                        'delivery_total' =>
                            round(
                                $deliveryTotal,
                                2
                            ),

                        'grand_total' =>
                            $grandTotal,

                        'currency' => 'NPR',

                        'store_count' =>
                            count(
                                $storeCalculations
                            ),

                        'status' => 'pending',

                        'expires_at' =>
                            $validUntil,

                        'snapshot_json' =>
                            json_encode(
                                $storeCalculations,
                                JSON_THROW_ON_ERROR
                            ),

                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                $savedStoreQuotes = [];

                foreach (
                    $storeCalculations
                    as $calculation
                ) {
                    $storeQuote =
                        $this->saveStoreQuote(
                            $checkoutQuoteId,
                            $checkoutQuoteNumber,
                            $calculation,
                            $data,
                            $merchantId
                        );

                    $savedStoreQuotes[] =
                        $storeQuote;
                }

                return [
                    'checkout_quote_id' =>
                        $checkoutQuoteId,

                    'checkout_quote_number' =>
                        $checkoutQuoteNumber,

                    'currency' => 'NPR',

                    'store_count' =>
                        count($savedStoreQuotes),

                    'products_total' =>
                        round(
                            $productsTotal,
                            2
                        ),

                    'pod_total' =>
                        round($podTotal, 2),

                    'delivery_total' =>
                        round(
                            $deliveryTotal,
                            2
                        ),

                    'grand_total' =>
                        $grandTotal,

                    'valid_until' =>
                        $validUntil
                            ->toIso8601String(),

                    'store_quotes' =>
                        $savedStoreQuotes,
                ];
            }
        );
    }

    private function storeSummary(
        array $store
    ): array {
        $weight = 0.0;
        $value = 0.0;
        $fragile = false;

        foreach ($store['items'] as $item) {
            $quantity =
                (int) $item['quantity'];

            $weight +=
                (float) $item['unit_weight']
                * $quantity;

            $value +=
                (float) $item['unit_price']
                * $quantity;

            if (
                $item['parcel_type'] ===
                'fragile'
            ) {
                $fragile = true;
            }
        }

        return [
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

    private function saveStoreQuote(
        int $checkoutQuoteId,
        string $checkoutQuoteNumber,
        array $calculation,
        array $data,
        ?int $merchantId
    ): array {
        $store = $calculation['store'];
        $summary = $calculation['summary'];
        $quote = $calculation['quote'];

        $storeQuoteNumber =
            $this->quoteNumber('QT');

        $pricingQuoteId =
            DB::table(
                'pricing_quotes'
            )->insertGetId([
                'checkout_quote_id' =>
                    $checkoutQuoteId,

                'quote_number' =>
                    $storeQuoteNumber,

                'merchant_id' =>
                    $merchantId,

                'store_id' =>
                    $store['store_id'],

                'pickup_branch_id' =>
                    $quote['pickup_branch'][
                        'id'
                    ],

                'delivery_branch_id' =>
                    $quote[
                        'delivery_branch'
                    ]['id'],

                'pickup_address' =>
                    $store[
                        'pickup_address'
                    ],

                'pickup_latitude' =>
                    $store[
                        'pickup_latitude'
                    ],

                'pickup_longitude' =>
                    $store[
                        'pickup_longitude'
                    ],

                'delivery_address' =>
                    $data['delivery'][
                        'address'
                    ],

                'delivery_latitude' =>
                    $data['delivery'][
                        'latitude'
                    ],

                'delivery_longitude' =>
                    $data['delivery'][
                        'longitude'
                    ],

                'parcel_weight' =>
                    $summary[
                        'parcel_weight'
                    ],

                'parcel_value' =>
                    $summary[
                        'parcel_value'
                    ],

                'parcel_type' =>
                    $summary[
                        'parcel_type'
                    ],

                'payment_type' =>
                    $data['payment_type'],

                'pod_amount' =>
                    $calculation[
                        'pod_amount'
                    ],

                'service_type' =>
                    $quote[
                        'service_type'
                    ]['code'],

                'service_type_id' =>
                    $quote[
                        'service_type'
                    ]['id'],

                'final_price' =>
                    $quote['final_price'],

                'currency' =>
                    $quote['currency'],

                'estimated_hours' =>
                    $quote[
                        'estimated_hours'
                    ],

                'sla_due_at' =>
                    $quote['sla_due_at'],

                'expires_at' =>
                    $quote['valid_until'],

                'snapshot_json' =>
                    json_encode(
                        $quote,
                        JSON_THROW_ON_ERROR
                    ),

                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        foreach ($store['items'] as $item) {
            $quantity =
                (int) $item['quantity'];

            DB::table(
                'pricing_quote_items'
            )->insert([
                'pricing_quote_id' =>
                    $pricingQuoteId,

                'store_id' =>
                    $store['store_id'],

                'product_id' =>
                    $item['product_id']
                    ?? null,

                'product_name' =>
                    $item['name'],

                'sku' =>
                    $item['sku']
                    ?? null,

                'quantity' =>
                    $quantity,

                'unit_weight' =>
                    $item['unit_weight'],

                'total_weight' =>
                    round(
                        (float) $item[
                            'unit_weight'
                        ] * $quantity,
                        3
                    ),

                'unit_price' =>
                    $item['unit_price'],

                'total_price' =>
                    round(
                        (float) $item[
                            'unit_price'
                        ] * $quantity,
                        2
                    ),

                'parcel_type' =>
                    $item['parcel_type'],

                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'pricing_quote_id' =>
                $pricingQuoteId,

            'quote_number' =>
                $storeQuoteNumber,

            'checkout_quote_number' =>
                $checkoutQuoteNumber,

            'store_id' =>
                $store['store_id'],

            'parcel_weight' =>
                $summary[
                    'parcel_weight'
                ],

            'parcel_value' =>
                $summary[
                    'parcel_value'
                ],

            'parcel_type' =>
                $summary[
                    'parcel_type'
                ],

            'cod_amount' =>
                $calculation[
                    'cod_amount'
                ],

            'pickup_branch' =>
                $quote[
                    'pickup_branch'
                ],

            'delivery_branch' =>
                $quote[
                    'delivery_branch'
                ],

            'delivery_fee' =>
                $quote['final_price'],

            'estimated_hours' =>
                $quote[
                    'estimated_hours'
                ],

            'sla_due_at' =>
                $quote[
                    'sla_due_at'
                ]->toIso8601String(),

            'breakdown' =>
                $quote['breakdown'],
        ];
    }

    private function quoteNumber(
        string $prefix
    ): string {
        return sprintf(
            '%s-%s-%s',
            $prefix,
            now()->format('YmdHis'),
            Str::upper(Str::random(6))
        );
    }
}