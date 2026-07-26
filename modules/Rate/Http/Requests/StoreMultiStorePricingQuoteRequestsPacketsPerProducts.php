<?php

namespace Modules\Rate\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMultiStorePricingQuoteRequestOLD extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $delivery = $this->input('delivery', []);

        if (!is_array($delivery)) {
            $delivery = [];
        }

        $serviceType = $this->normalizeServiceType(
            $this->input(
                'service_type',
                'standard'
            )
        );

        $paymentType = $this->normalizePaymentType(
            $this->input(
                'payment_type',
                'prepaid'
            )
        );

        /*
         * Support either:
         *
         * "store": {...}
         *
         * or:
         *
         * "stores": [{...}, {...}]
         */
        $stores = $this->input('stores');

        if (!is_array($stores)) {
            $singleStore = $this->input('store');

            $stores = is_array($singleStore)
                ? [$singleStore]
                : [];
        }

        $normalizedStores = [];

        foreach ($stores as $store) {
            if (!is_array($store)) {
                $normalizedStores[] = $store;

                continue;
            }

            $normalizedStores[] =
                $this->normalizeStore(
                    store: $store,
                    defaultServiceType: $serviceType,
                    defaultPaymentType: $paymentType
                );
        }

        /*
         * Replace the original payload with a consistent
         * marketplace pricing structure.
         */
        $this->replace([
            'external_checkout_id' =>
                $this->input(
                    'external_checkout_id'
                ),

            'delivery_address' =>
                $this->input(
                    'delivery_address',
                    $delivery['address'] ?? null
                ),

            'delivery_latitude' =>
                $this->input(
                    'delivery_latitude',
                    $delivery['latitude'] ?? null
                ),

            'delivery_longitude' =>
                $this->input(
                    'delivery_longitude',
                    $delivery['longitude'] ?? null
                ),

            'service_type' =>
                $serviceType,

            'payment_type' =>
                $paymentType,

            'stores' =>
                $normalizedStores,
        ]);
    }

    private function normalizeStore(
        array $store,
        string $defaultServiceType,
        string $defaultPaymentType
    ): array {
        $pickup = $store['pickup'] ?? [];

        if (!is_array($pickup)) {
            $pickup = [];
        }

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

        /*
         * Support older "items" payloads as products.
         */
        $products =
            $store['products']
            ?? $store['items']
            ?? null;

        $packets =
            $store['packets']
            ?? null;

        /*
         * Empty arrays must be treated as absent.
         *
         * This prevents:
         *
         * stores.0.packets must have at least 1 items
         */
        $hasProducts =
            is_array($products) &&
            count($products) > 0;

        $hasPackets =
            is_array($packets) &&
            count($packets) > 0;

        $normalized = [
            'store_id' =>
                isset($store['store_id'])
                    ? (int) $store['store_id']
                    : null,

            'external_store_id' =>
                $store['external_store_id']
                    ?? null,

            'pickup_address' =>
                $store['pickup_address']
                    ?? $pickup['address']
                    ?? null,

            'pickup_latitude' =>
                $store['pickup_latitude']
                    ?? $pickup['latitude']
                    ?? null,

            'pickup_longitude' =>
                $store['pickup_longitude']
                    ?? $pickup['longitude']
                    ?? null,

            'service_type' =>
                $serviceType,

            'payment_type' =>
                $paymentType,

            'pod_amount' =>
                isset($store['pod_amount'])
                    ? (float) $store['pod_amount']
                    : null,
        ];

        /*
         * Product-based marketplace shipment.
         */
        if ($hasProducts) {
            $productResult =
                $this->normalizeProducts(
                    $products
                );

            $normalized['pricing_input_mode'] =
                'products';

            $normalized['products'] =
                $productResult['products'];

            $normalized['packet_count'] =
                $productResult['packet_count'];

            $normalized['parcel_weight'] =
                $productResult['parcel_weight'];

            $normalized['parcel_value'] =
                $productResult['parcel_value'];

            $normalized['parcel_type'] =
                $productResult['parcel_type'];

            /*
             * Do not add:
             *
             * 'packets' => []
             */
            return $normalized;
        }

        /*
         * Direct physical-packet shipment.
         */
        if ($hasPackets) {
            $packetResult =
                $this->normalizePackets(
                    $packets
                );

            $normalized['pricing_input_mode'] =
                'packets';

            $normalized['packets'] =
                $packetResult['packets'];

            $normalized['packet_count'] =
                $packetResult['packet_count'];

            $normalized['parcel_weight'] =
                $packetResult['parcel_weight'];

            $normalized['parcel_value'] =
                $packetResult['parcel_value'];

            $normalized['parcel_type'] =
                $packetResult['parcel_type'];

            /*
             * Do not add:
             *
             * 'products' => []
             */
            return $normalized;
        }

        /*
         * Legacy aggregate parcel mode.
         */
        $hasLegacyParcel =
            array_key_exists(
                'parcel_weight',
                $store
            ) &&
            $store['parcel_weight'] !== null &&
            $store['parcel_weight'] !== '';

        if ($hasLegacyParcel) {
            $normalized['pricing_input_mode'] =
                'legacy_single_parcel';

            $normalized['parcel_weight'] =
                max(
                    0,
                    (float) $store['parcel_weight']
                );

            $normalized['parcel_value'] =
                max(
                    0,
                    (float) (
                        $store['parcel_value']
                        ?? 0
                    )
                );

            $normalized['parcel_type'] =
                $this->normalizeParcelType(
                    $store['parcel_type']
                        ?? 'non_fragile'
                );

            $normalized['packet_count'] =
                max(
                    1,
                    (int) (
                        $store['packet_count']
                        ?? 1
                    )
                );

            return $normalized;
        }

        /*
         * Validation will return a proper error because
         * no products, packets or parcel weight exists.
         */
        $normalized['pricing_input_mode'] =
            'missing';

        return $normalized;
    }

    private function normalizeProducts(
        array $products
    ): array {
        $normalizedProducts = [];

        $totalWeight = 0.0;
        $totalValue = 0.0;
        $packetCount = 0;
        $containsFragile = false;

        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }

            $quantity = max(
                1,
                (int) (
                    $product['quantity']
                    ?? 1
                )
            );

            $unitWeight = max(
                0,
                (float) (
                    $product['unit_weight']
                    ?? 0
                )
            );

            $unitPrice = max(
                0,
                (float) (
                    $product['unit_price']
                    ?? 0
                )
            );

            $parcelType =
                $this->normalizeParcelType(
                    $product['parcel_type']
                        ?? 'non_fragile'
                );

            if ($parcelType === 'fragile') {
                $containsFragile = true;
            }

            $packetCount += $quantity;

            $totalWeight +=
                $quantity * $unitWeight;

            $totalValue +=
                $quantity * $unitPrice;

            $normalizedProducts[] = [
                ...$product,

                'quantity' =>
                    $quantity,

                'unit_weight' =>
                    $unitWeight,

                'unit_price' =>
                    $unitPrice,

                'parcel_type' =>
                    $parcelType,
            ];
        }

        /*
         * Whole-store fragile rule:
         *
         * If one product is fragile, all products and
         * all resulting packets become fragile.
         */
        if ($containsFragile) {
            $normalizedProducts = array_map(
                static function (
                    array $product
                ): array {
                    $product['parcel_type'] =
                        'fragile';

                    return $product;
                },
                $normalizedProducts
            );
        }

        return [
            'products' =>
                $normalizedProducts,

            'packet_count' =>
                max(1, $packetCount),

            'parcel_weight' =>
                round($totalWeight, 3),

            'parcel_value' =>
                round($totalValue, 2),

            'parcel_type' =>
                $containsFragile
                    ? 'fragile'
                    : 'non_fragile',
        ];
    }

    private function normalizePackets(
        array $packets
    ): array {
        $normalizedPackets = [];

        $totalWeight = 0.0;
        $totalValue = 0.0;
        $containsFragile = false;

        foreach ($packets as $packet) {
            if (!is_array($packet)) {
                continue;
            }

            $actualWeight = max(
                0,
                (float) (
                    $packet['actual_weight']
                    ?? $packet['weight']
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

            $parcelType =
                $this->normalizeParcelType(
                    $packet['parcel_type']
                        ?? 'non_fragile'
                );

            $isFragile =
                $parcelType === 'fragile' ||
                (bool) (
                    $packet['is_fragile']
                    ?? false
                );

            if ($isFragile) {
                $containsFragile = true;
            }

            $totalWeight +=
                $actualWeight;

            $totalValue +=
                $declaredValue;

            $normalizedPackets[] = [
                ...$packet,

                'actual_weight' =>
                    $actualWeight,

                'declared_value' =>
                    $declaredValue,

                'quantity' => 1,

                'parcel_type' =>
                    $isFragile
                        ? 'fragile'
                        : 'non_fragile',

                'is_fragile' =>
                    $isFragile,
            ];
        }

        /*
         * If one physical packet is fragile, mark all
         * packets from this store as fragile.
         */
        if ($containsFragile) {
            $normalizedPackets = array_map(
                static function (
                    array $packet
                ): array {
                    $packet['parcel_type'] =
                        'fragile';

                    $packet['is_fragile'] =
                        true;

                    return $packet;
                },
                $normalizedPackets
            );
        }

        return [
            'packets' =>
                $normalizedPackets,

            'packet_count' =>
                max(
                    1,
                    count($normalizedPackets)
                ),

            'parcel_weight' =>
                round($totalWeight, 3),

            'parcel_value' =>
                round($totalValue, 2),

            'parcel_type' =>
                $containsFragile
                    ? 'fragile'
                    : 'non_fragile',
        ];
    }

    public function rules(): array
    {
        return [
            'external_checkout_id' => [
                'nullable',
                'string',
                'max:150',
            ],

            'delivery_address' => [
                'required',
                'string',
                'max:500',
            ],

            'delivery_latitude' => [
                'required',
                'numeric',
                'between:-90,90',
            ],

            'delivery_longitude' => [
                'required',
                'numeric',
                'between:-180,180',
            ],

            'service_type' => [
                'required',
                Rule::in([
                    'standard',
                    'express',
                    'same_day',
                ]),
            ],

            'payment_type' => [
                'required',
                Rule::in([
                    'prepaid',
                    'pod',
                ]),
            ],

            'stores' => [
                'required',
                'array',
                'min:1',
                'max:50',
            ],

            'stores.*.store_id' => [
                'nullable',
                'integer',
                'distinct',
            ],

            'stores.*.external_store_id' => [
                'nullable',
                'string',
                'max:150',
                'distinct',
            ],

            'stores.*.pickup_address' => [
                'required',
                'string',
                'max:500',
            ],

            'stores.*.pickup_latitude' => [
                'required',
                'numeric',
                'between:-90,90',
            ],

            'stores.*.pickup_longitude' => [
                'required',
                'numeric',
                'between:-180,180',
            ],

            'stores.*.pricing_input_mode' => [
                'required',
                Rule::in([
                    'products',
                    'packets',
                    'legacy_single_parcel',
                ]),
            ],

            /*
             * "sometimes" means validation only runs when
             * the field exists.
             *
             * Empty arrays are removed during normalization.
             */
            'stores.*.products' => [
                'sometimes',
                'array',
                'min:1',
            ],

            'stores.*.products.*.product_id' => [
                'nullable',
                'string',
                'max:100',
            ],

            'stores.*.products.*.name' => [
                'required',
                'string',
                'max:255',
            ],

            'stores.*.products.*.quantity' => [
                'required',
                'integer',
                'min:1',
            ],

            'stores.*.products.*.unit_weight' => [
                'required',
                'numeric',
                'min:0.001',
            ],

            'stores.*.products.*.unit_price' => [
                'required',
                'numeric',
                'min:0',
            ],

            'stores.*.products.*.parcel_type' => [
                'required',
                Rule::in([
                    'fragile',
                    'non_fragile',
                ]),
            ],

            'stores.*.products.*.length_cm' => [
                'nullable',
                'numeric',
                'min:0.1',
            ],

            'stores.*.products.*.width_cm' => [
                'nullable',
                'numeric',
                'min:0.1',
            ],

            'stores.*.products.*.height_cm' => [
                'nullable',
                'numeric',
                'min:0.1',
            ],

            'stores.*.packets' => [
                'sometimes',
                'array',
                'min:1',
            ],

            'stores.*.packets.*.packet_id' => [
                'nullable',
                'string',
                'max:100',
            ],

            'stores.*.packets.*.name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'stores.*.packets.*.actual_weight' => [
                'required',
                'numeric',
                'min:0.001',
            ],

            'stores.*.packets.*.declared_value' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'stores.*.packets.*.parcel_type' => [
                'required',
                Rule::in([
                    'fragile',
                    'non_fragile',
                ]),
            ],

            'stores.*.packets.*.is_fragile' => [
                'nullable',
                'boolean',
            ],

            'stores.*.packets.*.length_cm' => [
                'nullable',
                'numeric',
                'min:0.1',
            ],

            'stores.*.packets.*.width_cm' => [
                'nullable',
                'numeric',
                'min:0.1',
            ],

            'stores.*.packets.*.height_cm' => [
                'nullable',
                'numeric',
                'min:0.1',
            ],

            'stores.*.parcel_weight' => [
                'required',
                'numeric',
                'min:0.001',
            ],

            'stores.*.parcel_value' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'stores.*.parcel_type' => [
                'required',
                Rule::in([
                    'fragile',
                    'non_fragile',
                ]),
            ],

            'stores.*.packet_count' => [
                'required',
                'integer',
                'min:1',
            ],

            'stores.*.service_type' => [
                'required',
                Rule::in([
                    'standard',
                    'express',
                    'same_day',
                ]),
            ],

            'stores.*.payment_type' => [
                'required',
                Rule::in([
                    'prepaid',
                    'pod',
                ]),
            ],

            'stores.*.pod_amount' => [
                'nullable',
                'numeric',
                'min:0',
            ],
        ];
    }

    public function withValidator(
        Validator $validator
    ): void {
        $validator->after(
            function (
                Validator $validator
            ): void {
                $stores = $this->input(
                    'stores',
                    []
                );

                if (!is_array($stores)) {
                    return;
                }

                foreach (
                    $stores as $index => $store
                ) {
                    if (!is_array($store)) {
                        continue;
                    }

                    /*
                     * Marketplace must provide either its
                     * external store ID or Tukaatu store ID.
                     */
                    $hasStoreId =
                        !empty($store['store_id']);

                    $hasExternalStoreId =
                        !empty(
                            $store[
                                'external_store_id'
                            ]
                        );

                    if (
                        !$hasStoreId &&
                        !$hasExternalStoreId
                    ) {
                        $validator
                            ->errors()
                            ->add(
                                "stores.{$index}.external_store_id",
                                'Each marketplace store must provide store_id or external_store_id.'
                            );
                    }

                    if (
                        ($store['pricing_input_mode']
                            ?? null) === 'missing'
                    ) {
                        $validator
                            ->errors()
                            ->add(
                                "stores.{$index}.products",
                                'Each store must provide products, packets or parcel_weight.'
                            );
                    }

                    if (
                        ($store['payment_type']
                            ?? null) === 'pod' &&
                        (
                            !array_key_exists(
                                'pod_amount',
                                $store
                            ) ||
                            $store['pod_amount']
                                === null ||
                            $store['pod_amount']
                                === ''
                        )
                    ) {
                        $validator
                            ->errors()
                            ->add(
                                "stores.{$index}.pod_amount",
                                'The POD amount is required for this store.'
                            );
                    }
                }
            }
        );
    }

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
                $value,
        };
    }

    private function normalizePaymentType(
        mixed $value
    ): string {
        $value = strtolower(
            trim((string) $value)
        );

        return match ($value) {
            'cod',
            'cash_on_delivery',
            'cash-on-delivery' =>
                'pod',

            default =>
                $value,
        };
    }

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
                $value,
        };
    }

    public function messages(): array
    {
        return [
            'stores.required' =>
                'At least one marketplace store is required.',

            'stores.min' =>
                'At least one marketplace store is required.',

            'stores.*.products.min' =>
                'Products cannot be an empty array.',

            'stores.*.packets.min' =>
                'Packets cannot be an empty array.',

            'stores.*.parcel_weight.required' =>
                'Each store must provide products, packets or parcel weight.',
        ];
    }
}