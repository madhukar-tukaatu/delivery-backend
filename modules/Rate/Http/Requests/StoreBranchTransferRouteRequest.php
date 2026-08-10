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

    protected function prepareForValidation(): void
    {
        $this->merge([
            'route_type' => 'transfer',

            'transit_branch_ids' => array_values(
                array_map(
                    'intval',
                    $this->input(
                        'transit_branch_ids',
                        []
                    )
                )
            ),
        ]);
    }

    public function rules(): array
    {
        return [
            'route_type' => [
                'required',
                'string',
                Rule::in(['transfer']),
            ],

            'route_code' => [
                'required',
                'string',
                'max:100',
                'unique:branch_transfer_routes,route_code',
            ],

            'name' => [
                'required',
                'string',
                'max:255',
            ],

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

            'transit_branch_ids' => [
                'nullable',
                'array',
                'max:3',
            ],

            'transit_branch_ids.*' => [
                'required',
                'integer',
                'distinct',
                'exists:branches,id',
            ],

            'service_type' => [
                'required',
                Rule::in([
                    'standard',
                    'express',
                    'same_day',
                ]),
            ],

            'base_rate' => [
                'required',
                'numeric',
                'min:0',
            ],

            'currency' => [
                'nullable',
                'string',
                'size:3',
            ],

            'priority' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'is_default' => [
                'nullable',
                'boolean',
            ],

            'is_active' => [
                'nullable',
                'boolean',
            ],

            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $origin = (int) $this->input(
                'origin_branch_id'
            );

            $destination = (int) $this->input(
                'destination_branch_id'
            );

            $transits = array_map(
                'intval',
                $this->input(
                    'transit_branch_ids',
                    []
                )
            );

            if (
                in_array(
                    $origin,
                    $transits,
                    true
                )
            ) {
                $validator->errors()->add(
                    'transit_branch_ids',
                    'The origin branch cannot be a transit branch.'
                );
            }

            if (
                in_array(
                    $destination,
                    $transits,
                    true
                )
            ) {
                $validator->errors()->add(
                    'transit_branch_ids',
                    'The destination branch cannot be a transit branch.'
                );
            }
        });
    }
}