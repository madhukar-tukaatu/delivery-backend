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
            'pickup_type' => ['nullable', 'in:merchant_location,self_drop'],
            'pickup_location_id' => ['nullable', 'integer'],
            'order_reference' => ['nullable', 'string', 'max:100'],

            'customer.name' => ['required', 'string', 'max:150'],
            'customer.phone' => ['required', 'string', 'max:30'],
            'customer.email' => ['nullable', 'email', 'max:150'],

            'delivery.address' => ['required', 'string', 'max:500'],
            'delivery.city' => ['required', 'string', 'max:100'],
            'delivery.area' => ['nullable', 'string', 'max:100'],
            'delivery.landmark' => ['nullable', 'string', 'max:200'],
            'delivery.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'delivery.longitude' => ['nullable', 'numeric', 'between:-180,180'],

            'package.type' => ['nullable', 'string', 'max:50'],
            'package.description' => ['nullable', 'string', 'max:500'],
            'package.weight' => ['required', 'numeric', 'min:0.1'],
            'package.length_cm' => ['nullable', 'numeric', 'min:0'],
            'package.width_cm' => ['nullable', 'numeric', 'min:0'],
            'package.height_cm' => ['nullable', 'numeric', 'min:0'],
            'package.pieces' => ['nullable', 'integer', 'min:1'],
            'package.value' => ['nullable', 'numeric', 'min:0'],

            'payment.type' => ['nullable', 'in:prepaid,pod'],
            'payment.pod_amount' => ['nullable', 'numeric', 'min:0'],
            'payment.delivery_charge_paid_by' => ['nullable', 'in:merchant,customer'],

            'delivery_type' => ['nullable', 'in:standard,express,same_day'],
            'special_instruction' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $pickupType = $this->input('pickup_type', 'merchant_location');
            if ($pickupType !== 'self_drop' && !$this->filled('pickup_location_id')) {
                $validator->errors()->add('pickup_location_id', 'Pickup location is required.');
            }

            if ($this->input('payment.type') === 'pod' && (float) $this->input('payment.pod_amount', 0) <= 0) {
                $validator->errors()->add('payment.pod_amount', 'POD amount must be greater than zero.');
            }
        });
    }
}
