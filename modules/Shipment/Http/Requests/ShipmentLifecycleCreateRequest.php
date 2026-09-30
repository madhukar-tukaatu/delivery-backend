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

        $package = (array) $this->input('package', []);
        $weight = data_get($package, 'weight')
            ?? $this->input('weight')
            ?? $this->input('package_weight')
            ?? $this->input('parcel_weight');

        $customer = (array) $this->input('customer', []);
        $payment = (array) $this->input('payment', []);

        $this->merge([
            'delivery' => array_merge($delivery, array_filter([
                'latitude' => $latitude,
                'longitude' => $longitude,
                'address' => data_get($delivery, 'address') ?? $this->input('delivery_address') ?? $this->input('receiver_address'),
                'city' => data_get($delivery, 'city') ?? $this->input('delivery_city') ?? $this->input('receiver_city'),
                'area' => data_get($delivery, 'area') ?? $this->input('delivery_area') ?? $this->input('receiver_area'),
            ], fn ($v) => $v !== null && $v !== '')),
            'package' => array_merge($package, array_filter([
                'weight' => $weight,
                'type' => data_get($package, 'type') ?? $this->input('package_type'),
                'description' => data_get($package, 'description') ?? $this->input('package_description'),
                'value' => data_get($package, 'value') ?? $this->input('package_value') ?? $this->input('declared_value'),
            ], fn ($v) => $v !== null && $v !== '')),
            'customer' => array_merge($customer, array_filter([
                'name' => data_get($customer, 'name') ?? $this->input('receiver_name'),
                'phone' => data_get($customer, 'phone') ?? $this->input('receiver_phone'),
                'email' => data_get($customer, 'email') ?? $this->input('receiver_email'),
            ], fn ($v) => $v !== null && $v !== '')),
            'payment' => array_merge($payment, array_filter([
                'type' => data_get($payment, 'type') ?? $this->input('payment_type'),
                'pod_amount' => data_get($payment, 'pod_amount') ?? $this->input('pod_amount'),
                'delivery_charge_paid_by' => data_get($payment, 'delivery_charge_paid_by') ?? $this->input('delivery_charge_paid_by'),
                'delivery_charge' => data_get($payment, 'delivery_charge') ?? $this->input('delivery_charge'),
            ], fn ($v) => $v !== null && $v !== '')),
            'weight' => $weight,
            'delivery_charge' => $this->input('delivery_charge') ?? data_get($payment, 'delivery_charge'),
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
