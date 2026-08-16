<?php

namespace Modules\Rate\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBranchRouteRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active'            => $this->boolean('is_active', true),
            'express_enabled'      => $this->boolean('express_enabled', true),
            'same_day_enabled'     => $this->boolean('same_day_enabled', true),
            'create_reverse_route' => $this->boolean('create_reverse_route'),
        ]);
    }

    public function rules(): array
    {
        return [
            'pickup_coverage_location_id' => [
                'required',
                'integer',
                Rule::exists('coverage_locations', 'id')->where('type', 'main_branch_zone'),
            ],
            'delivery_coverage_location_id' => [
                'required',
                'integer',
                Rule::exists('coverage_locations', 'id')->where('type', 'main_branch_zone'),
            ],
            'base_rate'  => ['required', 'numeric', 'gte:0'],
            'is_active'  => ['required', 'boolean'],
            'express_enabled'  => ['required', 'boolean'],
            'same_day_enabled' => ['required', 'boolean'],

            'branch_transfer_route_id' => [
                'nullable',
                'integer',
                Rule::exists('branch_transfer_routes', 'id'),
            ],

            'create_reverse_route' => ['required', 'boolean'],
            'reverse_base_rate' => [
                'nullable',
                'required_if:create_reverse_route,true',
                'numeric',
                'gte:0',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $pickup = (int) $this->input('pickup_coverage_location_id');
                $delivery = (int) $this->input('delivery_coverage_location_id');

                if (
                    $pickup === $delivery &&
                    $this->boolean('create_reverse_route')
                ) {
                    $validator->errors()->add(
                        'create_reverse_route',
                        'A same-zone rate cannot create a separate reverse route.'
                    );
                }
            },
        ];
    }
}
