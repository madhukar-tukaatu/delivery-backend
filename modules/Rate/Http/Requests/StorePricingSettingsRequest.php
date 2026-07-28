<?php

namespace Modules\Rate\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePricingSettingsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'vat_inclusive' => true,
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'base_weight_kg' => ['required', 'numeric', 'min:0'],
            'base_distance_km' => ['required', 'numeric', 'min:0'],
            'local_extra_weight_rate' => ['required', 'numeric', 'min:0'],
            'transfer_extra_weight_rate' => ['required', 'numeric', 'min:0'],
            'extra_distance_rate' => ['required', 'numeric', 'min:0'],
            'fragile_multiplier' => ['required', 'numeric', 'min:1'],
            'local_same_day_multiplier' => ['required', 'numeric', 'min:1'],
            'transfer_same_day_multiplier' => ['required', 'numeric', 'min:1'],
            'same_day_cutoff_time' => ['required', 'date_format:H:i'],
            'minimum_free_pickup_packets' => ['required', 'integer', 'min:1'],
            'small_pickup_charge' => ['required', 'numeric', 'min:0'],
            'vat_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'vat_inclusive' => ['required', 'boolean'],
            'weight_rounding' => [
                'required',
                Rule::in(['none', 'round', 'ceil', 'floor']),
            ],
            'distance_rounding' => [
                'required',
                Rule::in(['none', 'round', 'ceil', 'floor']),
            ],
            'money_rounding' => [
                'required',
                Rule::in(['none', 'round', 'ceil', 'floor']),
            ],
            'fragile_enabled' => ['required', 'boolean'],
            'same_day_enabled' => ['required', 'boolean'],
            'pickup_charge_enabled' => ['required', 'boolean'],
            'vat_enabled' => ['required', 'boolean'],
            'activate' => ['sometimes', 'boolean'],
        ];
    }
}
