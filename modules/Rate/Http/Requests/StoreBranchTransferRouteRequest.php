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
                'nullable',
                'string',
                'max:100',
                'unique:branch_transfer_routes,route_code',
            ],

            'name' => ['nullable', 'string', 'max:255'],

            // A route is an ordered list of LANES (branch-to-branch connections).
            // 1 lane = direct route; 2+ lanes = route via transit branches.
            // Origin/destination/transits are DERIVED from the lane chain.
            'lane_ids'   => ['required', 'array', 'min:1', 'max:10'],
            'lane_ids.*' => ['integer', 'distinct', 'exists:branch_transfer_lanes,id'],

            // Map-picked road waypoints (NOT branches). Optional, describe the road path.
            'checkpoints'             => ['nullable', 'array', 'max:20'],
            'checkpoints.*.name'      => ['nullable', 'string', 'max:255'],
            'checkpoints.*.city'      => ['nullable', 'string', 'max:255'],
            'checkpoints.*.landmark'  => ['nullable', 'string', 'max:255'],
            'checkpoints.*.latitude'  => ['nullable', 'numeric', 'between:-90,90'],
            'checkpoints.*.longitude' => ['nullable', 'numeric', 'between:-180,180'],

            'service_type' => ['required', Rule::in(['standard', 'express', 'same_day', 'flight'])],
            'base_rate'    => ['nullable', 'numeric', 'min:0'],
            'currency'     => ['nullable', 'string', 'size:3'],
            'priority'     => ['nullable', 'integer', 'min:1'],
            'is_default'   => ['nullable', 'boolean'],
            'is_active'    => ['nullable', 'boolean'],
            'notes'        => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'lane_ids.required'   => 'Select at least one lane to build the route.',
            'lane_ids.*.exists'   => 'One of the selected lanes does not exist.',
            'lane_ids.*.distinct' => 'A lane cannot be used twice in the same route.',
        ];
    }
}
