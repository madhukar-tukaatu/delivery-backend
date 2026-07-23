<?php

namespace Modules\Rate\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePublicPricingQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $products = $this->input('products', []);

        $parcelWeight = $this->input('parcel_weight');
        $parcelValue = $this->input('parcel_value');
        $parcelType = strtolower(
            (string) $this->input(
                'parcel_type',
                'non_fragile'
            )
        );

        /*
         * When products are provided, calculate the parcel totals
         * automatically before validation.
         *
         * This supports both:
         * - single product
         * - multiple products
         */
        if (is_array($products) && count($products) > 0) {
            $calculatedWeight = 0;
            $calculatedValue = 0;
            $containsFragileProduct = false;

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

                $productParcelType = strtolower(
                    (string) (
                        $product['parcel_type']
                        ?? 'non_fragile'
                    )
                );

                $calculatedWeight +=
                    $quantity * $unitWeight;

                $calculatedValue +=
                    $quantity * $unitPrice;

                if ($productParcelType === 'fragile') {
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

            /*
             * If any selected product is fragile,
             * treat the complete parcel as fragile.
             */
            $parcelType = $containsFragileProduct
                ? 'fragile'
                : 'non_fragile';
        }

        $paymentType = strtolower(
            (string) $this->input('payment_type')
        );

        /*
         * Optionally accept "cod" from external stores
         * and normalize it to the system's "pod" value.
         */
        if ($paymentType === 'cod') {
            $paymentType = 'pod';
        }

        $this->merge([
            'parcel_weight' => $parcelWeight,
            'parcel_value' => $parcelValue,
            'parcel_type' => $parcelType,

            'payment_type' => $paymentType,

            'service_type' => strtolower(
                (string) $this->input('service_type')
            ),

            'packet_count' => (int) $this->input(
                'packet_count',
                1
            ),
        ]);
    }

    public function rules(): array
    {
        return [
            'store_id' => [
                'nullable',
                'integer',
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
             * Supports one or multiple products.
             *
             * If products are provided, parcel weight/value/type
             * are calculated automatically in prepareForValidation().
             */
            'products' => [
                'nullable',
                'array',
                'min:1',
                'required_without:parcel_weight',
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
            ],

            'products.*.unit_weight' => [
                'required_with:products',
                'numeric',
                'min:0.001',
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

            /*
             * This remains required because it is either:
             * - sent directly, or
             * - generated from products before validation.
             */
            'parcel_weight' => [
                'required',
                'numeric',
                'min:0.001',
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

            'packet_count' => [
                'required',
                'integer',
                'min:1',
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

    public function messages(): array
    {
        return [
            'products.required_without' =>
                'Provide either selected products or the total parcel weight.',

            'products.*.quantity.min' =>
                'Each product quantity must be at least 1.',

            'products.*.unit_weight.min' =>
                'Each product unit weight must be greater than zero.',

            'pod_amount.required_if' =>
                'The collection amount is required for payment on delivery.',
        ];
    }
}