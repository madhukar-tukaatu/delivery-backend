<?php

namespace Modules\Rate\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StorePublicPricingQuoteRequest extends FormRequest
{
    private bool $hasPacketsInput = false;

    private bool $hasProductsInput = false;

    private bool $hasLegacyParcelInput = false;

    private ?int $derivedPacketCount = null;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $rawPackets = $this->input('packets');
        $rawProducts = $this->input('products');

        $this->hasPacketsInput =
            is_array($rawPackets) && count($rawPackets) > 0;

        $this->hasProductsInput =
            is_array($rawProducts) && count($rawProducts) > 0;

        $this->hasLegacyParcelInput =
            !$this->hasPacketsInput &&
            !$this->hasProductsInput &&
            $this->filled('parcel_weight');

        $paymentType = strtolower(
            trim((string) $this->input('payment_type'))
        );

        if ($paymentType === 'cod') {
            $paymentType = 'pod';
        }

        $serviceType = strtolower(
            trim((string) $this->input('service_type'))
        );

        $parcelWeight = $this->input('parcel_weight');
        $parcelValue = $this->input('parcel_value');
        $parcelType = $this->normalizeParcelType(
            (string) $this->input(
                'parcel_type',
                'non_fragile'
            )
        );

        $packets = $rawPackets;
        $products = $rawProducts;

        if ($this->hasPacketsInput) {
            $packets = $this->normalizePackets($rawPackets);

            $parcelWeight = round(
                array_sum(
                    array_map(
                        static fn (array $packet): float =>
                            (float) (
                                $packet['actual_weight_kg']
                                ?? 0
                            ),
                        $packets
                    )
                ),
                3
            );

            $parcelValue = round(
                array_sum(
                    array_map(
                        static fn (array $packet): float =>
                            (float) (
                                $packet['unit_price']
                                ?? $packet['declared_value']
                                ?? 0
                            ),
                        $packets
                    )
                ),
                2
            );

            $parcelType = collect($packets)->contains(
                static fn (array $packet): bool =>
                    ($packet['parcel_type'] ?? null)
                    === 'fragile'
            )
                ? 'fragile'
                : 'non_fragile';

            $this->derivedPacketCount = count($packets);
        } elseif ($this->hasProductsInput) {
            $products = $this->normalizeProducts($rawProducts);

            $calculatedWeight = 0.0;
            $calculatedValue = 0.0;
            $containsFragileProduct = false;
            $packetCount = 0;

            foreach ($products as $product) {
                $quantity = max(
                    0,
                    (int) ($product['quantity'] ?? 0)
                );

                $unitWeight = max(
                    0,
                    (float) ($product['unit_weight'] ?? 0)
                );

                $unitPrice = max(
                    0,
                    (float) ($product['unit_price'] ?? 0)
                );

                $calculatedWeight +=
                    $quantity * $unitWeight;

                $calculatedValue +=
                    $quantity * $unitPrice;

                $packetCount += $quantity;

                if (
                    ($product['parcel_type'] ?? null)
                    === 'fragile'
                ) {
                    $containsFragileProduct = true;
                }
            }

            $parcelWeight = round(
                $calculatedWeight,
                3
            );

            $parcelValue = round(
                $calculatedValue,
                2
            );

            $parcelType = $containsFragileProduct
                ? 'fragile'
                : 'non_fragile';

            $this->derivedPacketCount = $packetCount;
        } elseif ($this->hasLegacyParcelInput) {
            $this->derivedPacketCount = 1;
        }

        $packetCount = $this->filled('packet_count')
            ? (int) $this->input('packet_count')
            : ($this->derivedPacketCount ?? 1);

        $this->merge([
            'packets' => $packets,
            'products' => $products,

            /*
             * Aggregate summary fields are retained for
             * backwards-compatible persistence and responses.
             * Packet-level pricing still comes from packets[]
             * or products[].
             */
            'parcel_weight' => $parcelWeight,
            'parcel_value' => $parcelValue,
            'parcel_type' => $parcelType,

            'payment_type' => $paymentType,
            'service_type' => $serviceType,
            'packet_count' => $packetCount,
        ]);
    }

    public function rules(): array
    {
        return [
            'store_id' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'pickup_address' => [
                'required',
                'string',
                'max:500',
            ],

            'pickup_latitude' => [
                'required',
                'numeric',
                'between:-90,90',
            ],

            'pickup_longitude' => [
                'required',
                'numeric',
                'between:-180,180',
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

            /*
             * Input option 1:
             * One entry represents one physical packet.
             */
            'packets' => [
                'nullable',
                'array',
                'min:1',
                'max:500',
            ],

            'packets.*.packet_reference' => [
                'nullable',
                'string',
                'max:100',
                'distinct',
            ],

            'packets.*.product_id' => [
                'nullable',
                'string',
                'max:100',
            ],

            'packets.*.name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'packets.*.quantity' => [
                'nullable',
                'integer',
                'in:1',
            ],

            'packets.*.actual_weight_kg' => [
                'required_with:packets',
                'numeric',
                'min:0.001',
                'max:10000',
            ],

            'packets.*.unit_price' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'packets.*.declared_value' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'packets.*.parcel_type' => [
                'required_with:packets',
                Rule::in([
                    'fragile',
                    'non_fragile',
                ]),
            ],

            'packets.*.length_cm' => [
                'nullable',
                'numeric',
                'min:0.001',
                'max:10000',
            ],

            'packets.*.width_cm' => [
                'nullable',
                'numeric',
                'min:0.001',
                'max:10000',
            ],

            'packets.*.height_cm' => [
                'nullable',
                'numeric',
                'min:0.001',
                'max:10000',
            ],

            /*
             * Input option 2:
             * Products are expanded into one physical packet
             * per quantity unit by PricingEngineService.
             */
            'products' => [
                'nullable',
                'array',
                'min:1',
                'max:500',
            ],

            'products.*.product_id' => [
                'nullable',
                'string',
                'max:100',
            ],

            'products.*.name' => [
                'required_with:products',
                'string',
                'max:255',
            ],

            'products.*.quantity' => [
                'required_with:products',
                'integer',
                'min:1',
                'max:500',
            ],

            'products.*.unit_weight' => [
                'required_with:products',
                'numeric',
                'min:0.001',
                'max:10000',
            ],

            'products.*.unit_price' => [
                'required_with:products',
                'numeric',
                'min:0',
            ],

            'products.*.parcel_type' => [
                'nullable',
                Rule::in([
                    'fragile',
                    'non_fragile',
                ]),
            ],

            'products.*.length_cm' => [
                'nullable',
                'numeric',
                'min:0.001',
                'max:10000',
            ],

            'products.*.width_cm' => [
                'nullable',
                'numeric',
                'min:0.001',
                'max:10000',
            ],

            'products.*.height_cm' => [
                'nullable',
                'numeric',
                'min:0.001',
                'max:10000',
            ],

            /*
             * Input option 3:
             * Backwards-compatible single-parcel request.
             */
            'parcel_weight' => [
                'required',
                'numeric',
                'min:0.001',
                'max:10000',
            ],

            'parcel_value' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'parcel_type' => [
                'required',
                Rule::in([
                    'fragile',
                    'non_fragile',
                ]),
            ],

            'parcel_length_cm' => [
                'nullable',
                'numeric',
                'min:0.001',
                'max:10000',
            ],

            'parcel_width_cm' => [
                'nullable',
                'numeric',
                'min:0.001',
                'max:10000',
            ],

            'parcel_height_cm' => [
                'nullable',
                'numeric',
                'min:0.001',
                'max:10000',
            ],

            'packet_count' => [
                'required',
                'integer',
                'min:1',
                'max:500',
            ],

            'payment_type' => [
                'required',
                Rule::in([
                    'pod',
                    'prepaid',
                ]),
            ],

            'pod_amount' => [
                'nullable',
                'required_if:payment_type,pod',
                'numeric',
                'min:0',
            ],

            'service_type' => [
                'required',
                Rule::in([
                    'standard',
                    'express',
                    'same_day',
                ]),
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $sourceCount = collect([
                    $this->hasPacketsInput,
                    $this->hasProductsInput,
                    $this->hasLegacyParcelInput,
                ])->filter()->count();

                if ($sourceCount === 0) {
                    $validator->errors()->add(
                        'parcel',
                        'Provide packets, selected products, or the total parcel weight.'
                    );

                    return;
                }

                if ($sourceCount > 1) {
                    $validator->errors()->add(
                        'parcel',
                        'Use only one parcel input method: packets, products, or total parcel weight.'
                    );
                }

                if (
                    $this->derivedPacketCount !== null &&
                    (int) $this->input('packet_count')
                    !== $this->derivedPacketCount
                ) {
                    $validator->errors()->add(
                        'packet_count',
                        "The packet count must be {$this->derivedPacketCount}."
                    );
                }

                if (
                    $this->hasLegacyParcelInput &&
                    (int) $this->input('packet_count') !== 1
                ) {
                    $validator->errors()->add(
                        'packet_count',
                        'A legacy parcel request supports exactly one physical packet.'
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'packets.*.actual_weight_kg.required_with' =>
                'Every physical packet must have an actual weight.',

            'packets.*.quantity.in' =>
                'Each packets entry represents one physical packet, so its quantity must be 1.',

            'packets.*.parcel_type.in' =>
                'Packet parcel type must be fragile or non_fragile.',

            'products.*.quantity.min' =>
                'Each product quantity must be at least 1.',

            'products.*.unit_weight.min' =>
                'Each product unit weight must be greater than zero.',

            'products.*.parcel_type.in' =>
                'Product parcel type must be fragile or non_fragile.',

            'parcel_weight.required' =>
                'Provide packets, selected products, or the total parcel weight.',

            'pod_amount.required_if' =>
                'The collection amount is required for payment on delivery.',
        ];
    }

    private function normalizePackets(array $packets): array
    {
        return array_map(
            function (mixed $packet): mixed {
                if (!is_array($packet)) {
                    return $packet;
                }

                $packet['quantity'] = (int) (
                    $packet['quantity'] ?? 1
                );

                if (
                    !array_key_exists(
                        'actual_weight_kg',
                        $packet
                    ) &&
                    array_key_exists('parcel_weight', $packet)
                ) {
                    $packet['actual_weight_kg'] =
                        $packet['parcel_weight'];
                }

                $packet['parcel_type'] =
                    $this->normalizeParcelType(
                        (string) (
                            $packet['parcel_type']
                            ?? 'non_fragile'
                        )
                    );

                $this->normalizeDimensions($packet);

                return $packet;
            },
            $packets
        );
    }

    private function normalizeProducts(array $products): array
    {
        return array_map(
            function (mixed $product): mixed {
                if (!is_array($product)) {
                    return $product;
                }

                $product['parcel_type'] =
                    $this->normalizeParcelType(
                        (string) (
                            $product['parcel_type']
                            ?? 'non_fragile'
                        )
                    );

                $this->normalizeDimensions($product);

                return $product;
            },
            $products
        );
    }

    private function normalizeDimensions(array &$item): void
    {
        $aliases = [
            'length_cm' => [
                'parcel_length_cm',
                'length',
                'parcel_length',
            ],
            'width_cm' => [
                'parcel_width_cm',
                'width',
                'parcel_width',
            ],
            'height_cm' => [
                'parcel_height_cm',
                'height',
                'parcel_height',
            ],
        ];

        foreach ($aliases as $target => $possibleKeys) {
            if (
                array_key_exists($target, $item) &&
                $item[$target] !== null &&
                $item[$target] !== ''
            ) {
                continue;
            }

            foreach ($possibleKeys as $key) {
                if (
                    array_key_exists($key, $item) &&
                    $item[$key] !== null &&
                    $item[$key] !== ''
                ) {
                    $item[$target] = $item[$key];
                    break;
                }
            }
        }
    }

    private function normalizeParcelType(
        string $parcelType
    ): string {
        $normalized = strtolower(
            trim($parcelType)
        );

        return match ($normalized) {
            'non-fragile',
            'non fragile',
            'nonfragile' =>
                'non_fragile',

            default =>
                $normalized,
        };
    }
}