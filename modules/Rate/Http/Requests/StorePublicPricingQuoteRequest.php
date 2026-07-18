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
        $this->merge([
            'parcel_type' => strtolower(
                (string) $this->input(
                    'parcel_type',
                    'non_fragile'
                )
            ),
            'payment_type' => strtolower(
                (string) $this->input('payment_type')
            ),
            'service_type' => strtolower(
                (string) $this->input('service_type')
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
}