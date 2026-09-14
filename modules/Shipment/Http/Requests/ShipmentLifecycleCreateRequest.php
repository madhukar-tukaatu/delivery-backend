<?php

namespace Modules\Shipment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShipmentLifecycleCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $delivery = (array) $this->input('delivery', []);
        $latitude = data_get($delivery, 'latitude')
            ?? $this->input('delivery_lat')
            ?? $this->input('delivery_latitude');
        $longitude = data_get($delivery, 'longitude')
            ?? $this->input('delivery_lng')
            ?? $this->input('delivery_longitude');

        $this->merge([
            'delivery' => array_merge($delivery, [
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]),
        ]);
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
            'delivery.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'delivery.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'delivery_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'delivery_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'delivery_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'delivery_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'package.type' => ['nullable', 'string', 'max:100'],
            'package.description' => ['nullable', 'string'],
            'package.weight' => ['required_without:weight', 'numeric', 'min:0.1'],
            'package.length_cm' => ['nullable', 'numeric', 'min:0'],
            'package.width_cm' => ['nullable', 'numeric', 'min:0'],
            'package.height_cm' => ['nullable', 'numeric', 'min:0'],
            'package.pieces' => ['nullable', 'integer', 'min:1'],
            'package.value' => ['nullable', 'numeric', 'min:0'],
            'payment.type' => ['nullable', 'in:prepaid,pod'],
            'payment.pod_amount' => ['nullable', 'numeric', 'min:0'],
            'payment.delivery_charge_paid_by' => ['nullable', 'in:merchant,customer'],
            'special_instruction' => ['nullable', 'string'],
        ];
    }
}
