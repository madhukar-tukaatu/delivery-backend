<?php

namespace Modules\Rate\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePricingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'vat_inclusive' => $this->boolean('vat_inclusive'),
            'activate' => $this->boolean('activate', true),
        ]);
    }

    public function rules(): array
    {
        return [
            'included_weight_kg' => ['required', 'numeric', 'gt:0'],
            'same_branch_weight_rate' => ['required', 'numeric', 'gte:0'],
            'other_branch_weight_rate' => ['required', 'numeric', 'gte:0'],
            'volumetric_divisor' => ['required', 'numeric', 'gt:0'],

            'fragile_multiplier' => ['required', 'numeric', 'gte:1'],

            'included_delivery_distance_km' => ['required', 'numeric', 'gte:0'],
            'extra_distance_rate_per_km' => ['required', 'numeric', 'gte:0'],

            'same_branch_sdd_multiplier' => ['required', 'numeric', 'gte:1'],
            'other_branch_sdd_multiplier' => ['required', 'numeric', 'gte:1'],
            'same_day_cutoff_time' => ['required', 'date_format:H:i:s'],

            'minimum_pickup_packets' => ['required', 'integer', 'min:1'],
            'low_packet_pickup_charge' => ['required', 'numeric', 'gte:0'],

            'vat_inclusive' => ['required', 'boolean'],
            'vat_percentage' => ['required', 'numeric', 'between:0,100'],
            'quote_validity_minutes' => ['required', 'integer', 'min:1', 'max:1440'],

            'change_reason' => ['required', 'string', 'max:500'],
            'activate' => ['required', 'boolean'],
        ];
    }
}
