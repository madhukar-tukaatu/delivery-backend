<?php

namespace Modules\Rate\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreMultiStorePricingQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $stores = collect(
            $this->input('stores', [])
        )->map(function (array $store): array {
            $store['items'] = collect(
                $store['items'] ?? []
            )->map(function (array $item): array {
                $item['parcel_type'] = strtolower(
                    (string) (
                        $item['parcel_type']
                        ?? 'non_fragile'
                    )
                );

                return $item;
            })->all();

            return $store;
        })->all();

        $this->merge([
            'payment_type' => strtolower(
                (string) $this->input('payment_type')
            ),
            'service_type' => strtolower(
                (string) $this->input('service_type')
            ),
            'stores' => $stores,
        ]);
    }

    public function rules(): array
    {
        return [
            'delivery' => [
                'required',
                'array',
            ],

            'delivery.address' => [
                'required',
                'string',
                'max:500',
            ],

            'delivery.latitude' => [
                'required',
                'numeric',
                'between:-90,90',
            ],

            'delivery.longitude' => [
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
                    'pod',
                    'prepaid',
                ]),
            ],

            'stores' => [
                'required',
                'array',
                'min:1',
                'max:100',
            ],

            'stores.*.store_id' => [
                'required',
                'integer',
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

            'stores.*.items' => [
                'required',
                'array',
                'min:1',
            ],

            'stores.*.items.*.product_id' => [
                'nullable',
                'integer',
            ],

            'stores.*.items.*.name' => [
                'required',
                'string',
                'max:255',
            ],

            'stores.*.items.*.sku' => [
                'nullable',
                'string',
                'max:100',
            ],

            'stores.*.items.*.quantity' => [
                'required',
                'integer',
                'min:1',
            ],

            'stores.*.items.*.unit_weight' => [
                'required',
                'numeric',
                'min:0.001',
            ],

            'stores.*.items.*.unit_price' => [
                'required',
                'numeric',
                'min:0',
            ],

            'stores.*.items.*.parcel_type' => [
                'required',
                Rule::in([
                    'fragile',
                    'non_fragile',
                ]),
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $storeIds = collect(
                    $this->input('stores', [])
                )->pluck('store_id');

                if (
                    $storeIds->count() !==
                    $storeIds->unique()->count()
                ) {
                    $validator->errors()->add(
                        'stores',
                        'Each store may appear only once.'
                    );
                }
            },
        ];
    }
}