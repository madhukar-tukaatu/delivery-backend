<?php

declare(strict_types=1);

namespace Modules\Shipment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StorePublicMerchantShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'payment_type' => $this->filled('payment_type')
                ? strtolower((string) $this->input('payment_type'))
                : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'pricing_quote_id' => [
                'required',
                'integer',
            ],

            'merchant_order_id' => [
                'required',
                'string',
                'max:100',
            ],

            'sender' => [
                'required',
                'array',
            ],

            'sender.name' => [
                'required',
                'string',
                'max:150',
            ],

            'sender.phone' => [
                'required',
                'string',
                'max:30',
            ],

            'sender.email' => [
                'nullable',
                'email',
                'max:255',
            ],

            'sender.address' => [
                'required',
                'string',
                'max:500',
            ],

            'receiver' => [
                'required',
                'array',
            ],

            'receiver.name' => [
                'required',
                'string',
                'max:150',
            ],

            'receiver.phone' => [
                'required',
                'string',
                'max:30',
            ],

            'receiver.email' => [
                'nullable',
                'email',
                'max:255',
            ],

            'receiver.address' => [
                'required',
                'string',
                'max:500',
            ],

            'receiver.latitude' => [
                'required',
                'numeric',
                'between:-90,90',
            ],

            'receiver.longitude' => [
                'required',
                'numeric',
                'between:-180,180',
            ],

            'parcel' => [
                'required',
                'array',
            ],

            'parcel.description' => [
                'nullable',
                'string',
                'max:500',
            ],

            'parcel.weight' => [
                'required',
                'numeric',
                'min:0.01',
                'max:1000',
            ],

            'parcel.quantity' => [
                'required',
                'integer',
                'min:1',
                'max:10000',
            ],

            'parcel.value' => [
                'nullable',
                'numeric',
                'min:0',
                'max:999999999.99',
            ],

            'payment_type' => [
                'required',
                Rule::in([
                    'cod',
                    'prepaid',
                ]),
            ],

            'cod_amount' => [
                'nullable',
                'required_if:payment_type,cod',
                'numeric',
                'min:0.01',
                'max:999999999.99',
            ],

            'notes' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'cod_amount.required_if' =>
                'COD amount is required when payment type is COD.',
        ];
    }
}