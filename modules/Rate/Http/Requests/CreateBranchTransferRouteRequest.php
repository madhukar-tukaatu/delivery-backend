<?php

namespace Modules\Rate\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateBranchTransferRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_branch_id' => [
                'required',
                'integer',
                'exists:coverage_locations,id',
            ],
            'to_branch_id' => [
                'required',
                'integer',
                'exists:coverage_locations,id',
                'different:from_branch_id',
            ],
            'service_type' => [
                'required',
                'string',
                'in:standard,express,same_day,flight',
            ],
            'transit_branch_ids' => [
                'sometimes',
                'array',
            ],
            'transit_branch_ids.*' => [
                'integer',
                'exists:coverage_locations,id',
            ],
            'base_rate' => [
                'sometimes',
                'numeric',
                'min:0',
            ],
            'priority' => [
                'sometimes',
                'integer',
                'min:1',
            ],
            'is_active' => [
                'sometimes',
                'boolean',
            ],
            'notes' => [
                'sometimes',
                'string',
                'max:1000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'from_branch_id.required' => 'Origin branch is required',
            'from_branch_id.exists' => 'Selected origin branch does not exist',
            'to_branch_id.required' => 'Destination branch is required',
            'to_branch_id.exists' => 'Selected destination branch does not exist',
            'to_branch_id.different' => 'Destination must be different from origin',
            'service_type.required' => 'Service type is required',
            'service_type.in' => 'Invalid service type. Must be: standard, express, same_day, or flight',
        ];
    }
}
