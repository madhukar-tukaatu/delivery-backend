<?php

declare(strict_types=1);

namespace Modules\Rate\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateBranchPricingRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_types' => 'required|array|min:1',
            'service_types.standard' => 'required|array',
            'service_types.standard.enabled' => 'required|boolean',
            'service_types.standard.base_rate' => 'required_if:service_types.standard.enabled,true|numeric|min:1',
            'service_types.express' => 'nullable|array',
            'service_types.express.enabled' => 'nullable|boolean',
            'service_types.express.base_rate' => 'required_if:service_types.express.enabled,true|numeric|min:1',
            'service_types.same_day' => 'nullable|array',
            'service_types.same_day.enabled' => 'nullable|boolean',
            'service_types.same_day.base_rate' => 'required_if:service_types.same_day.enabled,true|numeric|min:1',
        ];
    }

    public function messages(): array
    {
        return [
            'service_types.standard.base_rate.required_if' => 'Standard base rate is required when enabled',
            'service_types.express.base_rate.required_if' => 'Express base rate is required when enabled',
            'service_types.same_day.base_rate.required_if' => 'Same day base rate is required when enabled',
        ];
    }
}
