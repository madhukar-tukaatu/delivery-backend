<?php

namespace Modules\Rate\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTransferRoutePricingProfileRequest extends
    FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mode' => [
                'required',
                Rule::in([
                    'global',
                    'custom',
                ]),
            ],

            'custom_pricing' => [
                'nullable',
                'array',
                'required_if:mode,custom',
            ],

            'custom_pricing.name' => [
                'required_if:mode,custom',
                'nullable',
                'string',
                'max:255',
            ],

            'custom_pricing.base_weight_kg' => [
                'required_if:mode,custom',
                'nullable',
                'numeric',
                'min:0',
            ],

            'custom_pricing.base_distance_km' => [
                'required_if:mode,custom',
                'nullable',
                'numeric',
                'min:0',
            ],

            'custom_pricing.transfer_extra_weight_rate' => [
                'required_if:mode,custom',
                'nullable',
                'numeric',
                'min:0',
            ],

            'custom_pricing.extra_distance_rate' => [
                'required_if:mode,custom',
                'nullable',
                'numeric',
                'min:0',
            ],

            'custom_pricing.fragile_multiplier' => [
                'required_if:mode,custom',
                'nullable',
                'numeric',
                'min:1',
            ],

            'custom_pricing.transfer_same_day_multiplier' => [
                'required_if:mode,custom',
                'nullable',
                'numeric',
                'min:1',
            ],

            'custom_pricing.same_day_cutoff_time' => [
                'required_if:mode,custom',
                'nullable',
                'date_format:H:i',
            ],

            'custom_pricing.minimum_free_pickup_packets' => [
                'required_if:mode,custom',
                'nullable',
                'integer',
                'min:1',
            ],

            'custom_pricing.small_pickup_charge' => [
                'required_if:mode,custom',
                'nullable',
                'numeric',
                'min:0',
            ],

            'custom_pricing.vat_percentage' => [
                'required_if:mode,custom',
                'nullable',
                'numeric',
                'min:0',
                'max:100',
            ],

            'custom_pricing.weight_rounding' => [
                'required_if:mode,custom',
                'nullable',
                Rule::in([
                    'none',
                    'round',
                    'ceil',
                    'floor',
                ]),
            ],

            'custom_pricing.distance_rounding' => [
                'required_if:mode,custom',
                'nullable',
                Rule::in([
                    'none',
                    'round',
                    'ceil',
                    'floor',
                ]),
            ],

            'custom_pricing.money_rounding' => [
                'required_if:mode,custom',
                'nullable',
                Rule::in([
                    'none',
                    'round',
                    'ceil',
                    'floor',
                ]),
            ],

            'custom_pricing.fragile_enabled' => [
                'required_if:mode,custom',
                'nullable',
                'boolean',
            ],

            'custom_pricing.same_day_enabled' => [
                'required_if:mode,custom',
                'nullable',
                'boolean',
            ],

            'custom_pricing.pickup_charge_enabled' => [
                'required_if:mode,custom',
                'nullable',
                'boolean',
            ],

            'custom_pricing.vat_enabled' => [
                'required_if:mode,custom',
                'nullable',
                'boolean',
            ],
        ];
    }
}