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

            'origin_branch_id' => [
                'required',
                'integer',
                'exists:branches,id',
            ],

            'destination_branch_id' => [
                'required',
                'integer',
                'different:origin_branch_id',
                'exists:branches,id',
            ],

            /*
             * 0 stops = direct route (origin → destination)
             * 1–5 stops = transit hubs in order
             */
            'stops'                          => ['nullable', 'array', 'max:5'],
            'stops.*.branch_id'              => ['required', 'integer', 'exists:branches,id'],
            'stops.*.distance_km'            => ['nullable', 'numeric', 'min:0'],
            'stops.*.estimated_hours'        => ['nullable', 'integer', 'min:0'],
            'stops.*.transport_mode'         => ['nullable', 'string', 'max:50'],

            /*
             * Distance and hours for the final leg (last stop → destination).
             * Only relevant when stops are provided.
             */
            'destination_distance_km'        => ['nullable', 'numeric', 'min:0'],
            'destination_estimated_hours'    => ['nullable', 'integer', 'min:0'],

            'service_type' => ['required', Rule::in(['standard', 'express', 'same_day'])],
            'priority'     => ['nullable', 'integer', 'min:1'],
            'is_default'   => ['nullable', 'boolean'],
            'is_active'    => ['nullable', 'boolean'],
            'notes'        => ['nullable', 'string', 'max:2000'],
        ];
    }
}
