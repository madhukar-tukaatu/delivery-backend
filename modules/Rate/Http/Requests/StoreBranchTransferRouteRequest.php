<?php

namespace Modules\Rate\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchTransferRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'route_code' => [
                'required',
                'string',
                'max:100',
                'unique:branch_transfer_routes,route_code',
            ],

            'name' => ['required', 'string', 'max:255'],

            // Routes reference coverage_locations (operational hubs), not the branches table.
            'origin_branch_id' => [
                'required',
                'integer',
                'exists:coverage_locations,id',
            ],

            'destination_branch_id' => [
                'required',
                'integer',
                'different:origin_branch_id',
                'exists:coverage_locations,id',
            ],

            // Ordered intermediate hubs (transits). Empty = direct route.
            'transit_branch_ids'   => ['nullable', 'array', 'max:5'],
            'transit_branch_ids.*' => ['integer', 'exists:coverage_locations,id'],

            'service_type' => ['required', Rule::in(['standard', 'express', 'same_day', 'flight'])],
            'priority'     => ['nullable', 'integer', 'min:1'],
            'is_default'   => ['nullable', 'boolean'],
            'is_active'    => ['nullable', 'boolean'],
            'notes'        => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'origin_branch_id.exists'      => 'The selected origin branch does not exist.',
            'destination_branch_id.exists' => 'The selected destination branch does not exist.',
            'destination_branch_id.different' => 'Destination must be different from origin.',
        ];
    }
}
