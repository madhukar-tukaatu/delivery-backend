<?php

namespace Modules\Rate\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchRouteRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('is_active')) {
            $this->merge(['is_active' => $this->boolean('is_active')]);
        }
        if ($this->has('express_enabled')) {
            $this->merge(['express_enabled' => $this->boolean('express_enabled')]);
        }
        if ($this->has('same_day_enabled')) {
            $this->merge(['same_day_enabled' => $this->boolean('same_day_enabled')]);
        }
    }

    public function rules(): array
    {
        return [
            'base_rate'        => ['required', 'numeric', 'gte:0'],
            'is_active'        => ['required', 'boolean'],
            'express_enabled'  => ['required', 'boolean'],
            'same_day_enabled' => ['required', 'boolean'],

            'branch_transfer_route_id' => [
                'nullable',
                'integer',
                Rule::exists('branch_transfer_routes', 'id'),
            ],
        ];
    }
}
