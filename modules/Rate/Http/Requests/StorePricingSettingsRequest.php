<?php

namespace Modules\Rate\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePricingSettingsRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $maxWeight = $this->input('max_weight_kg');
        $minCharge = $this->input('min_delivery_charge');
        $maxCharge = $this->input('max_delivery_charge');

        $this->merge([
            'vat_inclusive' => true,
            'max_weight_kg' => ($maxWeight === '' || $maxWeight === null) ? null : $maxWeight,
            'weight_limit_enabled' => filter_var(
                $this->input('weight_limit_enabled', false),
                FILTER_VALIDATE_BOOLEAN
            ),
            'min_delivery_charge' => ($minCharge === '' || $minCharge === null) ? 0 : $minCharge,
            'max_delivery_charge' => ($maxCharge === '' || $maxCharge === null) ? null : $maxCharge,
            'delivery_charge_bounds_enabled' => filter_var(
                $this->input('delivery_charge_bounds_enabled', false),
                FILTER_VALIDATE_BOOLEAN
            ),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $weightLimitEnabled = (bool) $this->input('weight_limit_enabled', false);

        return [
            'name' => ['required', 'string', 'max:255'],
            'base_weight_kg' => ['required', 'numeric', 'min:0'],
            'max_weight_kg' => [
                $weightLimitEnabled ? 'required' : 'nullable',
                'numeric',
                'gt:0',
                'gte:base_weight_kg',
            ],
            'weight_limit_enabled' => ['required', 'boolean'],
            'base_distance_km' => ['required', 'numeric', 'min:0'],
            'local_extra_weight_rate' => ['required', 'numeric', 'min:0'],
            'transfer_extra_weight_rate' => ['required', 'numeric', 'min:0'],
            'extra_distance_rate' => ['required', 'numeric', 'min:0'],
            'fragile_multiplier' => ['required', 'numeric', 'min:1'],
            'local_same_day_multiplier' => ['required', 'numeric', 'min:1'],
            'transfer_same_day_multiplier' => ['required', 'numeric', 'min:1'],
            'local_express_multiplier' => ['required', 'numeric', 'min:1'],
            'transfer_express_multiplier' => ['required', 'numeric', 'min:1'],
            'same_day_cutoff_time' => ['required', 'date_format:H:i'],
            'minimum_free_pickup_packets' => ['required', 'integer', 'min:1'],
            'small_pickup_charge' => ['required', 'numeric', 'min:0'],
            'min_delivery_charge' => ['required', 'numeric', 'min:0'],
            'max_delivery_charge' => ['nullable', 'numeric', 'gt:0', 'gte:min_delivery_charge'],
            'delivery_charge_bounds_enabled' => ['required', 'boolean'],
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
            'express_enabled' => ['required', 'boolean'],
            'pickup_charge_enabled' => ['required', 'boolean'],
            'vat_enabled' => ['required', 'boolean'],
            'activate' => ['sometimes', 'boolean'],
        ];
    }
}
