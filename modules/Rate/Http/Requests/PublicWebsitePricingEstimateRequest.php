<?php

declare(strict_types=1);

namespace Modules\Rate\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PublicWebsitePricingEstimateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $serviceType = strtolower(trim((string) $this->input(
            'service_type',
            'standard'
        )));

        $serviceType = match ($serviceType) {
            'same-day',
            'same day',
            'sameday' => 'same_day',
            default => $serviceType !== ''
                ? $serviceType
                : 'standard',
        };

        $weightMode = strtolower(trim((string) $this->input(
            'weight_mode',
            'actual'
        )));

        $weightMode = match ($weightMode) {
            'volume',
            'dimension',
            'dimensions' => 'volumetric',
            default => $weightMode !== ''
                ? $weightMode
                : 'actual',
        };

        $this->merge([
            'service_type' => $serviceType,
            'weight_mode' => $weightMode,
        ]);
    }

    public function rules(): array
    {
        $usesActualWeight = fn (): bool =>
            $this->input('weight_mode') === 'actual';

        $usesVolumetricWeight = fn (): bool =>
            $this->input('weight_mode') === 'volumetric';

        return [
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

            'service_type' => [
                'required',
                'string',
                Rule::in([
                    'standard',
                    'express',
                    'same_day',
                ]),
            ],

            'weight_mode' => [
                'required',
                Rule::in([
                    'actual',
                    'volumetric',
                ]),
            ],

            'parcel_dimensions' => [
                Rule::requiredIf($usesVolumetricWeight),
                'nullable',
                'array',
            ],

            'parcel_dimensions.length_cm' => [
                Rule::requiredIf($usesVolumetricWeight),
                'nullable',
                'numeric',
                'gt:0',
                'max:1000',
            ],

            'parcel_dimensions.width_cm' => [
                Rule::requiredIf($usesVolumetricWeight),
                'nullable',
                'numeric',
                'gt:0',
                'max:1000',
            ],

            'parcel_dimensions.height_cm' => [
                Rule::requiredIf($usesVolumetricWeight),
                'nullable',
                'numeric',
                'gt:0',
                'max:1000',
            ],

            'products' => [
                'required',
                'array',
                'min:1',
                'max:100',
            ],

            'products.*.product_id' => [
                'nullable',
                'string',
                'max:120',
            ],

            'products.*.name' => [
                'required',
                'string',
                'max:255',
            ],

            'products.*.quantity' => [
                'required',
                'integer',
                'min:1',
                'max:1000',
            ],

            'products.*.unit_weight' => [
                Rule::requiredIf($usesActualWeight),
                'nullable',
                'numeric',
                'gt:0',
                'max:500',
            ],

            'products.*.parcel_type' => [
                'required',
                Rule::in([
                    'non_fragile',
                    'fragile',
                ]),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'pickup_address.required' =>
                'Select the pickup location.',

            'delivery_address.required' =>
                'Select the delivery location.',

            'weight_mode.required' =>
                'Select actual or volumetric weight.',

            'products.required' =>
                'Add at least one product.',

            'products.*.name.required' =>
                'Enter the product name.',

            'products.*.quantity.required' =>
                'Enter the product quantity.',

            'products.*.unit_weight.required' =>
                'Enter the product unit actual weight.',

            'products.*.unit_weight.gt' =>
                'The product unit actual weight must be greater than zero.',

            'parcel_dimensions.required' =>
                'Enter the packed parcel dimensions.',

            'parcel_dimensions.length_cm.required' =>
                'Enter the packed parcel length.',

            'parcel_dimensions.width_cm.required' =>
                'Enter the packed parcel width.',

            'parcel_dimensions.height_cm.required' =>
                'Enter the packed parcel height.',
        ];
    }
}
