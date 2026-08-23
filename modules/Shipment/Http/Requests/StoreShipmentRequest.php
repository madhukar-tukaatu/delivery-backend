<?php

namespace Modules\Shipment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'service_type' =>
                $this->input('service_type', 'standard'),

            'parcel_type' =>
                $this->input('parcel_type', 'non_fragile'),

            'payment_type' =>
                $this->input('payment_type', 'prepaid'),
        ]);
    }

    public function rules(): array
    {
        return [

            /*
            |--------------------------------------------------------------------------
            | External Order
            |--------------------------------------------------------------------------
            */

            'external_order_id' => [
                'required',
                'string',
                'max:150',
            ],

            'external_checkout_id' => [
                'nullable',
                'string',
                'max:150',
            ],

            /*
            |--------------------------------------------------------------------------
            | Pickup
            |--------------------------------------------------------------------------
            */

            'pickup_location_id' => [
                'nullable',
                'integer',
            ],

            'pickup_address' => [
                'nullable',
                'string',
                'max:500',
            ],

            'pickup_latitude' => [
                'nullable',
                'numeric',
                'between:-90,90',
            ],

            'pickup_longitude' => [
                'nullable',
                'numeric',
                'between:-180,180',
            ],

            /*
            |--------------------------------------------------------------------------
            | Delivery
            |--------------------------------------------------------------------------
            */

            'delivery_name' => [
                'required',
                'string',
                'max:150',
            ],

            'delivery_phone' => [
                'required',
                'string',
                'max:30',
            ],

            'delivery_address' => [
                'required',
                'string',
                'max:500',
            ],

            'delivery_city' => [
                'nullable',
                'string',
                'max:100',
            ],

            'delivery_area' => [
                'nullable',
                'string',
                'max:100',
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
            |--------------------------------------------------------------------------
            | Parcel
            |--------------------------------------------------------------------------
            */

            'service_type' => [
                'required',
                Rule::in([
                    'standard',
                    'express',
                    'same_day',
                ]),
            ],

            'parcel_type' => [
                'required',
                Rule::in([
                    'fragile',
                    'non_fragile',
                ]),
            ],

            'actual_weight_kg' => [
                'required',
                'numeric',
                'gt:0',
            ],

            'declared_value' => [
                'nullable',
                'numeric',
                'gte:0',
            ],

            'payment_type' => [
                'required',
                Rule::in([
                    'prepaid',
                    'cod',
                ]),
            ],

            'cod_amount' => [
                'nullable',
                'numeric',
                'gte:0',
            ],

            'remarks' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }
}