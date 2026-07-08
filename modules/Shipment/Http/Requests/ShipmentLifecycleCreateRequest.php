<?php

namespace Modules\Shipment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShipmentLifecycleCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'merchant_id' => ['sometimes', 'integer'],
            'pickup_location_id' => ['nullable', 'integer'],
            'order_reference' => ['nullable', 'string', 'max:100'],
            'customer.name' => ['required_without:receiver_name', 'string', 'max:150'],
            'customer.phone' => ['required_without:receiver_phone', 'string', 'max:30'],
            'customer.email' => ['nullable', 'email'],
            'delivery.address' => ['required_without:receiver_address', 'string'],
            'delivery.city' => ['required_without:receiver_city', 'string', 'max:100'],
            'delivery.area' => ['nullable', 'string', 'max:100'],
            'delivery.latitude' => ['nullable', 'numeric'],
            'delivery.longitude' => ['nullable', 'numeric'],
            'package.type' => ['nullable', 'string', 'max:100'],
            'package.description' => ['nullable', 'string'],
            'package.weight' => ['required_without:weight', 'numeric', 'min:0.1'],
            'package.length_cm' => ['nullable', 'numeric', 'min:0'],
            'package.width_cm' => ['nullable', 'numeric', 'min:0'],
            'package.height_cm' => ['nullable', 'numeric', 'min:0'],
            'package.pieces' => ['nullable', 'integer', 'min:1'],
            'package.value' => ['nullable', 'numeric', 'min:0'],
            'payment.type' => ['nullable', 'in:prepaid,cod'],
            'payment.cod_amount' => ['nullable', 'numeric', 'min:0'],
            'payment.delivery_charge_paid_by' => ['nullable', 'in:merchant,customer'],
            'special_instruction' => ['nullable', 'string'],
        ];
    }
}
