<?php

namespace Modules\Shipment\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShipmentOperationsCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $paymentType = $this->input('payment_type')
            ?: data_get($this->input('payment'), 'type')
            ?: 'prepaid';

        $merchantOrderId = $this->input('merchant_order_id')
            ?: $this->input('order_reference');

        $customerName = $this->input('customer_name')
            ?: data_get($this->input('customer'), 'name');

        $customerPhone = $this->input('customer_phone')
            ?: data_get($this->input('customer'), 'phone');

        $customerEmail = $this->input('customer_email')
            ?: data_get($this->input('customer'), 'email');

        $deliveryAddress = $this->input('customer_address')
            ?: $this->input('delivery_address')
            ?: data_get($this->input('delivery'), 'address');

        $deliveryCity = $this->input('customer_city')
            ?: $this->input('delivery_city')
            ?: data_get($this->input('delivery'), 'city');

        $deliveryArea = $this->input('customer_area')
            ?: $this->input('delivery_area')
            ?: data_get($this->input('delivery'), 'area');

        $deliveryLat = $this->input('delivery_lat')
            ?: $this->input('delivery_latitude')
            ?: data_get($this->input('delivery'), 'latitude');

        $deliveryLng = $this->input('delivery_lng')
            ?: $this->input('delivery_longitude')
            ?: data_get($this->input('delivery'), 'longitude');

        $packageType = $this->input('package_type')
            ?: data_get($this->input('package'), 'type')
            ?: 'parcel';

        $packageDescription = $this->input('package_description')
            ?: data_get($this->input('package'), 'description');

        $weight = $this->input('weight')
            ?: data_get($this->input('package'), 'weight');

        $lengthCm = $this->input('length_cm')
            ?: data_get($this->input('package'), 'length_cm')
            ?: 0;

        $widthCm = $this->input('width_cm')
            ?: data_get($this->input('package'), 'width_cm')
            ?: 0;

        $heightCm = $this->input('height_cm')
            ?: data_get($this->input('package'), 'height_cm')
            ?: 0;

        $pieces = $this->input('pieces')
            ?: data_get($this->input('package'), 'pieces')
            ?: 1;

        $declaredValue = $this->input('declared_value')
            ?: data_get($this->input('package'), 'value')
            ?: 0;

        $codAmount = $this->input('pod_amount')
            ?: data_get($this->input('payment'), 'pod_amount')
            ?: 0;

        $deliveryChargePaidBy = $this->input('delivery_charge_paid_by')
            ?: data_get($this->input('payment'), 'delivery_charge_paid_by')
            ?: 'merchant';

        $this->merge([
            'self_drop' => (bool) $this->input('self_drop', false),
            'pickup_location_id' => $this->input('pickup_location_id'),

            'merchant_order_id' => $merchantOrderId,
            'order_reference' => $merchantOrderId,

            'customer_name' => $customerName,
            'customer_phone' => $customerPhone,
            'customer_email' => $customerEmail,

            'customer_address' => $deliveryAddress,
            'customer_city' => $deliveryCity,
            'customer_area' => $deliveryArea,

            'delivery_address' => $deliveryAddress,
            'delivery_city' => $deliveryCity,
            'delivery_area' => $deliveryArea,
            'delivery_lat' => $deliveryLat,
            'delivery_lng' => $deliveryLng,
            'delivery_latitude' => $deliveryLat,
            'delivery_longitude' => $deliveryLng,

            'package_type' => $packageType,
            'package_description' => $packageDescription,
            'weight' => $weight,
            'length_cm' => $lengthCm,
            'width_cm' => $widthCm,
            'height_cm' => $heightCm,
            'pieces' => $pieces,
            'declared_value' => $declaredValue,

            'payment_type' => $paymentType,
            'pod_amount' => $codAmount,
            'delivery_charge_paid_by' => $deliveryChargePaidBy,

            'customer' => [
                'name' => $customerName,
                'phone' => $customerPhone,
                'email' => $customerEmail,
            ],

            'delivery' => [
                'address' => $deliveryAddress,
                'city' => $deliveryCity,
                'area' => $deliveryArea,
                'latitude' => $deliveryLat,
                'longitude' => $deliveryLng,
            ],

            'package' => [
                'type' => $packageType,
                'description' => $packageDescription,
                'weight' => $weight,
                'length_cm' => $lengthCm,
                'width_cm' => $widthCm,
                'height_cm' => $heightCm,
                'pieces' => $pieces,
                'value' => $declaredValue,
            ],

            'payment' => [
                'type' => $paymentType,
                'pod_amount' => $codAmount,
                'delivery_charge_paid_by' => $deliveryChargePaidBy,
            ],
        ]);
    }

    public function rules(): array
    {
        return [
            'self_drop' => ['nullable', 'boolean'],
            'pickup_location_id' => ['nullable', 'integer'],

            'merchant_order_id' => ['required', 'string', 'max:100'],
            'order_reference' => ['nullable', 'string', 'max:100'],

            'customer.name' => ['required', 'string', 'max:150'],
            'customer.phone' => ['required', 'string', 'max:30'],
            'customer.email' => ['nullable', 'email', 'max:150'],

            'delivery.address' => ['required', 'string', 'max:500'],
            'delivery.city' => ['required', 'string', 'max:100'],
            'delivery.area' => ['nullable', 'string', 'max:100'],
            'delivery.latitude' => ['required', 'numeric', 'between:-90,90'],
            'delivery.longitude' => ['required', 'numeric', 'between:-180,180'],

            'package.type' => ['nullable', 'string', 'max:80'],
            'package.description' => ['nullable', 'string', 'max:500'],
            'package.weight' => ['required', 'numeric', 'min:0.01'],
            'package.length_cm' => ['nullable', 'numeric', 'min:0'],
            'package.width_cm' => ['nullable', 'numeric', 'min:0'],
            'package.height_cm' => ['nullable', 'numeric', 'min:0'],
            'package.pieces' => ['nullable', 'integer', 'min:1'],
            'package.value' => ['nullable', 'numeric', 'min:0'],

            'payment.type' => ['required', 'in:prepaid,pod'],
            'payment.pod_amount' => ['required_if:payment.type,pod', 'nullable', 'numeric', 'min:0'],
            'payment.delivery_charge_paid_by' => ['required', 'in:merchant,customer'],

            'special_instruction' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'merchant_order_id.required' => 'Merchant order ID is required.',
            'customer.name.required' => 'Customer name is required.',
            'customer.phone.required' => 'Customer phone is required.',
            'delivery.address.required' => 'Delivery address is required.',
            'delivery.city.required' => 'Delivery city is required.',
            'delivery.latitude.required' => 'Delivery latitude is required. Select delivery location from map.',
            'delivery.longitude.required' => 'Delivery longitude is required. Select delivery location from map.',
            'package.weight.required' => 'Package weight is required.',
            'payment.type.required' => 'Payment type is required.',
        ];
    }
}