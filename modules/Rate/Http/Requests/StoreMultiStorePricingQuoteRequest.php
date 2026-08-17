<?php

namespace Modules\Rate\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Rate\Enums\MarketplacePackingMode;

class StoreMultiStorePricingQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $serviceType = $this->normalizeServiceType(
            $this->input('service_type', 'standard')
        );

        $paymentType = $this->normalizePaymentType(
            $this->input('payment_type', 'prepaid')
        );

        $delivery = $this->input('delivery');

        $deliveryAddress = $this->input(
            'delivery_address',
            is_array($delivery) ? ($delivery['address'] ?? null) : null
        );

        $deliveryLatitude = $this->input(
            'delivery_latitude',
            is_array($delivery) ? ($delivery['latitude'] ?? null) : null
        );

        $deliveryLongitude = $this->input(
            'delivery_longitude',
            is_array($delivery) ? ($delivery['longitude'] ?? null) : null
        );

        $stores = $this->input('stores', []);
        $stores = is_array($stores) ? $stores : [];

        $normalizedStores = [];

        foreach ($stores as $store) {
            if (!is_array($store)) {
                $normalizedStores[] = $store;
                continue;
            }

            if (
                empty($store['products']) &&
                !empty($store['items']) &&
                is_array($store['items'])
            ) {
                $store['products'] = $store['items'];
            }

            $normalizedStores[] = $this->normalizeStore(
                $store,
                $serviceType,
                $paymentType
            );
        }

        $this->merge([
            'delivery_address' => $deliveryAddress,
            'delivery_latitude' => $deliveryLatitude,
            'delivery_longitude' => $deliveryLongitude,
            'service_type' => $serviceType,
            'payment_type' => $paymentType,
            'stores' => $normalizedStores,
        ]);
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
                Rule::in(['standard', 'express', 'same_day']),
            ],

            'payment_type' => [
                'required',
                Rule::in(['prepaid', 'pod']),
            ],

            'stores' => [
                'required',
                'array',
                'min:1',
                'max:50',
            ],

            /*
             * Marketplace stores may be identified either by Tukaatu's
             * internal store_id or the marketplace's external_store_id.
             */
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

            'stores.*.input_mode' => [
                'required',
                Rule::in([
                    'products',
                    'packets',
                    'legacy_single_parcel',
                    'mixed',
                    'missing',
                ]),
            ],

            // 'stores.*.packing_policy' => [
            //     'required',
            //     Rule::in([
            //         'single_per_store',
            //         'per_product_quantity',
            //         'explicit_packets',
            //     ]),
            // ],

            'stores.*.packing_policy' => [
                'required',
                Rule::in(
                    MarketplacePackingMode::values()
                ),
            ],

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
                'required_with:stores.*.products',
                'string',
                'max:255',
            ],

            'stores.*.products.*.sku' => [
                'nullable',
                'string',
                'max:100',
            ],

            'stores.*.products.*.quantity' => [
                'required_with:stores.*.products',
                'integer',
                'min:1',
            ],

            'stores.*.products.*.unit_weight' => [
                'nullable',
                'numeric',
                'min:0.001',
            ],

            'stores.*.products.*.unit_price' => [
                'required_with:stores.*.products',
                'numeric',
                'min:0',
            ],

            'stores.*.products.*.parcel_type' => [
                'nullable',
                Rule::in(['fragile', 'non_fragile']),
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

            'stores.*.packets.*.quantity' => [
                'nullable',
                'integer',
                Rule::in([1]),
            ],

            'stores.*.packets.*.actual_weight' => [
                'nullable',
                'numeric',
                'min:0.001',
            ],

            'stores.*.packets.*.actual_weight_kg' => [
                'nullable',
                'numeric',
                'min:0.001',
            ],

            'stores.*.packets.*.declared_value' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'stores.*.packets.*.parcel_type' => [
                'nullable',
                Rule::in(['fragile', 'non_fragile']),
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

            /*
             * Final combined package dimensions. These should describe the
             * actual packed parcel, not the sum of product dimensions.
             */
            'stores.*.package_length_cm' => [
                'nullable',
                'numeric',
                'min:0.1',
            ],

            'stores.*.package_width_cm' => [
                'nullable',
                'numeric',
                'min:0.1',
            ],

            'stores.*.package_height_cm' => [
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
                Rule::in(['fragile', 'non_fragile']),
            ],

            'stores.*.packet_count' => [
                'required',
                'integer',
                'min:1',
            ],

            'stores.*.service_type' => [
                'required',
                Rule::in(['standard', 'express', 'same_day']),
            ],

            'stores.*.payment_type' => [
                'required',
                Rule::in(['prepaid', 'pod']),
            ],

            'stores.*.pod_amount' => [
                'nullable',
                'numeric',
                'min:0',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $stores = $this->input('stores', []);

            if (!is_array($stores)) {
                return;
            }

            foreach ($stores as $index => $store) {
                if (!is_array($store)) {
                    continue;
                }

                $inputMode = $store['input_mode'] ?? 'missing';
                $packingPolicy = $store['packing_policy']
                    ?? $this->packingPolicy();

                if (
                    empty($store['store_id']) &&
                    empty($store['external_store_id'])
                ) {
                    $validator->errors()->add(
                        "stores.{$index}.external_store_id",
                        'Provide store_id or external_store_id for this store.'
                    );
                }

                if ($inputMode === 'mixed') {
                    $validator->errors()->add(
                        "stores.{$index}.products",
                        'Provide products or packets for one store, not both.'
                    );
                }

                if ($inputMode === 'missing') {
                    $validator->errors()->add(
                        "stores.{$index}.products",
                        'Provide products, packets or parcel_weight for this store.'
                    );
                }

                if (
                    $packingPolicy === 'explicit_packets' &&
                    empty($store['packets'])
                ) {
                    $validator->errors()->add(
                        "stores.{$index}.packets",
                        'Packets are required while the marketplace packing policy is explicit_packets.'
                    );
                }

                if (
                    ($store['payment_type'] ?? null) === 'pod' &&
                    !array_key_exists('pod_amount', $store)
                ) {
                    $validator->errors()->add(
                        "stores.{$index}.pod_amount",
                        'POD amount is required for this store.'
                    );
                }

                $dimensionKeys = [
                    'package_length_cm',
                    'package_width_cm',
                    'package_height_cm',
                ];

                $providedDimensions = collect($dimensionKeys)
                    ->filter(
                        static fn(string $key): bool =>
                        isset($store[$key]) && $store[$key] !== ''
                    )
                    ->count();

                if ($providedDimensions > 0 && $providedDimensions < 3) {
                    $validator->errors()->add(
                        "stores.{$index}.package",
                        'Provide all three final package dimensions or none of them.'
                    );
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'stores.required' => 'At least one marketplace store is required.',
            'stores.max' => 'A maximum of 50 stores can be priced per request.',
            'stores.*.store_id.distinct' => 'The same internal store cannot appear more than once.',
            'stores.*.external_store_id.distinct' => 'The same marketplace store cannot appear more than once.',
            'stores.*.packets.*.quantity.in' => 'Each packet entry must represent exactly one physical packet.',
        ];
    }

    private function normalizeStore(
        array $store,
        string $defaultServiceType,
        string $defaultPaymentType
    ): array {
        $products = is_array($store['products'] ?? null)
            ? array_values($store['products'])
            : [];

        $packets = is_array($store['packets'] ?? null)
            ? array_values($store['packets'])
            : [];

        $hasProducts = count($products) > 0;
        $hasPackets = count($packets) > 0;

        $inputMode = match (true) {
            $hasProducts && $hasPackets => 'mixed',
            $hasProducts => 'products',
            $hasPackets => 'packets',
            isset($store['parcel_weight']) &&
                $store['parcel_weight'] !== '' =>
            'legacy_single_parcel',
            default => 'missing',
        };

        $packingPolicy = $this->packingPolicy();

        $parcelWeight = $store['parcel_weight'] ?? null;
        $parcelValue = $store['parcel_value'] ?? null;
        $parcelType = $this->normalizeParcelType(
            $store['parcel_type'] ?? 'non_fragile'
        );
        $packetCount = max(
            1,
            (int) ($store['packet_count'] ?? 1)
        );

        if ($hasProducts) {
            $parcelWeight = 0.0;
            $parcelValue = 0.0;
            $productUnitCount = 0;
            $containsFragile = false;

            foreach ($products as $key => $product) {
                $quantity = max(
                    0,
                    (int) ($product['quantity'] ?? 0)
                );
                $unitWeight = isset($product['unit_weight']) && (float) $product['unit_weight'] > 0
                    ? (float) $product['unit_weight']
                    : $this->baseWeightFallback();
                $unitPrice = max(
                    0,
                    (float) ($product['unit_price'] ?? 0)
                );
                $productType = $this->normalizeParcelType(
                    $product['parcel_type'] ?? 'non_fragile'
                );

                $products[$key]['quantity'] = $quantity;
                $products[$key]['unit_weight'] = $unitWeight;
                $products[$key]['unit_price'] = $unitPrice;
                $products[$key]['parcel_type'] = $productType;

                $parcelWeight += $quantity * $unitWeight;
                $parcelValue += $quantity * $unitPrice;
                $productUnitCount += $quantity;
                $containsFragile =
                    $containsFragile || $productType === 'fragile';
            }

            $parcelType = $containsFragile
                ? 'fragile'
                : 'non_fragile';

            /*
             * Current policy forces one combined packet per store.
             * Future per-product mode uses the product quantity count.
             */
            $packetCount = $packingPolicy === 'single_per_store'
                ? 1
                : max(1, $productUnitCount);
        }

        if ($hasPackets) {
            $parcelWeight = 0.0;
            $parcelValue = 0.0;
            $containsFragile = false;

            foreach ($packets as $key => $packet) {
                $actualWeight = max(
                    0,
                    (float) (
                        $packet['actual_weight']
                        ?? $packet['actual_weight_kg']
                        ?? 0
                    )
                );
                $declaredValue = max(
                    0,
                    (float) ($packet['declared_value'] ?? 0)
                );
                $packetType = $this->normalizeParcelType(
                    $packet['parcel_type'] ?? 'non_fragile'
                );

                $packets[$key]['quantity'] = 1;
                $packets[$key]['actual_weight'] = $actualWeight;
                $packets[$key]['parcel_type'] = $packetType;
                $parcelWeight += $actualWeight;
                $parcelValue += $declaredValue;
                $containsFragile =
                    $containsFragile || $packetType === 'fragile';
            }

            $parcelType = $containsFragile
                ? 'fragile'
                : 'non_fragile';

            $packetCount = $packingPolicy === 'single_per_store'
                ? 1
                : max(1, count($packets));
        }

        $paymentType = $this->normalizePaymentType(
            $store['payment_type'] ?? $defaultPaymentType
        );

        $serviceType = $this->normalizeServiceType(
            $store['service_type'] ?? $defaultServiceType
        );

        if (
            $paymentType === 'pod' &&
            !array_key_exists('pod_amount', $store)
        ) {
            $store['pod_amount'] = round(
                (float) ($parcelValue ?? 0),
                2
            );
        }

        $package = is_array($store['package'] ?? null)
            ? $store['package']
            : [];

        $normalized = [
            ...$store,
            'input_mode' => $inputMode,
            'packing_policy' => $packingPolicy,
            'parcel_weight' => $parcelWeight !== null
                ? round((float) $parcelWeight, 3)
                : null,
            'parcel_value' => $parcelValue !== null
                ? round((float) $parcelValue, 2)
                : null,
            'parcel_type' => $parcelType,
            'packet_count' => max(1, $packetCount),
            'payment_type' => $paymentType,
            'service_type' => $serviceType,
            'package_length_cm' => $store['package_length_cm']
                ?? $package['length_cm']
                ?? null,
            'package_width_cm' => $store['package_width_cm']
                ?? $package['width_cm']
                ?? null,
            'package_height_cm' => $store['package_height_cm']
                ?? $package['height_cm']
                ?? null,
        ];

        unset($normalized['items'], $normalized['package']);

        if ($products !== []) {
            $normalized['products'] = $products;
        } else {
            unset($normalized['products']);
        }

        if ($packets !== []) {
            $normalized['packets'] = $packets;
        } else {
            unset($normalized['packets']);
        }

        return $normalized;
    }

    private function baseWeightFallback(): float
    {
        $settings = \Illuminate\Support\Facades\DB::table('pricing_settings')
            ->where('is_active', true)
            ->orderByDesc('id')
            ->value('included_weight_kg');

        return max(0.001, (float) ($settings ?? 1.5));
    }

    private function packingPolicy(): string
    {
        $policy = strtolower(trim((string) config(
            'marketplace.store_packet_mode',
            'single_per_store'
        )));

        return in_array(
            $policy,
            [
                'single_per_store',
                'per_product_quantity',
                'explicit_packets',
            ],
            true
        )
            ? $policy
            : 'single_per_store';
    }

    private function normalizeParcelType(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        $value = str_replace(['-', ' '], '_', $value);

        return match ($value) {
            'fragile' => 'fragile',
            'nonfragile',
            'non_fragile',
            'normal',
            'regular' => 'non_fragile',
            default => $value,
        };
    }

    private function normalizePaymentType(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        return match ($value) {
            'pod',
            'cash_on_delivery',
            'cash-on-delivery',
            'payment_on_delivery',
            'payment-on-delivery' => 'pod',
            default => $value,
        };
    }

    private function normalizeServiceType(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        return match ($value) {
            'same-day',
            'same day',
            'sameday' => 'same_day',
            default => $value,
        };
    }
}
