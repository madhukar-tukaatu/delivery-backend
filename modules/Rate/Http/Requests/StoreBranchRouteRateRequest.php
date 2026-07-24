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
            'is_active' => $this->boolean('is_active', true),
            'create_reverse_route' => $this->boolean('create_reverse_route'),
        ]);
    }

    public function rules(): array
    {
        return [
            'pickup_branch_id' => [
                'required',
                'integer',
                Rule::exists('branches', 'id')->whereNull('parent_id'),
            ],
            'delivery_branch_id' => [
                'required',
                'integer',
                Rule::exists('branches', 'id')->whereNull('parent_id'),
            ],
            'base_rate' => ['required', 'numeric', 'gte:0'],
            'is_active' => ['required', 'boolean'],

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
                $pickup = (int) $this->input('pickup_branch_id');
                $delivery = (int) $this->input('delivery_branch_id');

                if (
                    $pickup === $delivery &&
                    $this->boolean('create_reverse_route')
                ) {
                    $validator->errors()->add(
                        'create_reverse_route',
                        'A same-branch rate cannot create a separate reverse route.'
                    );
                }
            },
        ];
    }
}
