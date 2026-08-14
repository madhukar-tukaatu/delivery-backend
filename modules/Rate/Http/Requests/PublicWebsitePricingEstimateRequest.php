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

        $parcelType = strtolower(trim((string) $this->input(
            'parcel_type',
            'non_fragile'
        )));

        $parcelType = match ($parcelType) {
            'non-fragile',
            'non fragile',
            'normal' => 'non_fragile',
            default => $parcelType,
        };

        $weight = $this->input('actual_weight_kg');
        $weight = ($weight !== null && $weight !== '' && (float) $weight > 0)
            ? (float) $weight
            : 1.5;

        $this->merge([
            'service_type'     => $serviceType,
            'parcel_type'      => $parcelType,
            'actual_weight_kg' => $weight,
        ]);
    }

    public function rules(): array
    {
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
                ]),
            ],

            'parcel_type' => [
                'required',
                Rule::in([
                    'non_fragile',
                    'fragile',
                ]),
            ],

            'actual_weight_kg' => [
                'nullable',
                'numeric',
                'gt:0',
                'max:5000',
            ],

            'parcel_dimensions' => [
                'nullable',
                'array',
            ],

            'parcel_dimensions.length_cm' => [
                'nullable',
                'numeric',
                'gt:0',
                'max:1000',
            ],

            'parcel_dimensions.width_cm' => [
                'nullable',
                'numeric',
                'gt:0',
                'max:1000',
            ],

            'parcel_dimensions.height_cm' => [
                'nullable',
                'numeric',
                'gt:0',
                'max:1000',
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
        ];
    }
}
