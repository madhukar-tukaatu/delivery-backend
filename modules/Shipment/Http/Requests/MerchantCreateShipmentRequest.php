<?php
namespace Modules\Shipment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MerchantCreateShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [

            /*
            |--------------------------------------------------------------------------
            | Store's order
            |--------------------------------------------------------------------------
            */

            'external_order_id'        => [
                'required',
                'string',
                'max:100',
            ],

            'external_order_reference' => [
                'nullable',
                'string',
                'max:150',
            ],

            /*
            |--------------------------------------------------------------------------
            | Pickup
            |--------------------------------------------------------------------------
            */

            'pickup_location_id'       => [
                'nullable',
                'integer',
            ],

            'pickup_name'              => [
                'nullable',
                'string',
                'max:150',
            ],

            'pickup_phone'             => [
                'nullable',
                'string',
                'max:30',
            ],

            'pickup_address'           => [
                'nullable',
                'string',
                'max:500',
            ],

            'pickup_city'              => [
                'nullable',
                'string',
                'max:100',
            ],

            'pickup_area'              => [
                'nullable',
                'string',
                'max:100',
            ],

            'pickup_lat'               => [
                'nullable',
                'numeric',
                'between:-90,90',
            ],

            'pickup_lng'               => [
                'nullable',
                'numeric',
                'between:-180,180',
            ],

            /*
            |--------------------------------------------------------------------------
            | Delivery
            |--------------------------------------------------------------------------
            */

            'receiver_name'            => [
                'required',
                'string',
                'max:150',
            ],

            'receiver_phone'           => [
                'required',
                'string',
                'max:30',
            ],

            'delivery_address'         => [
                'required',
                'string',
                'max:500',
            ],

            'delivery_city'            => [
                'nullable',
                'string',
                'max:100',
            ],

            'delivery_area'            => [
                'nullable',
                'string',
                'max:100',
            ],

            'delivery_lat'             => [
                'required',
                'numeric',
                'between:-90,90',
            ],

            'delivery_lng'             => [
                'required',
                'numeric',
                'between:-180,180',
            ],

            /*
            |--------------------------------------------------------------------------
            | Shipment
            |--------------------------------------------------------------------------
            */

            'service_type'             => [
                'required',
                'string',
                'max:50',
            ],

            'parcel_type'              => [
                'nullable',
                'string',
                'max:50',
            ],

            'actual_weight_kg'         => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'declared_value'           => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'fragile'                  => [
                'nullable',
                'boolean',
            ],

            /*
            |--------------------------------------------------------------------------
            | Payment
            |--------------------------------------------------------------------------
            */

            'payment_type'             => [
                'required',
                'in:prepaid,pod',
            ],

            'pod_amount'               => [
                // Required (and greater than zero) for pay-on-delivery orders.
                'required_if:payment_type,pod',
                'nullable',
                'numeric',
                'gt:0',
            ],

            'delivery_charge'          => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'delivery_charge_paid_by'  => [
                'nullable',
                'in:merchant,customer',
            ],

            'remarks'                  => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }
}
