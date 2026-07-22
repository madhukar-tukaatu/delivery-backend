<?php

namespace Modules\Rate\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchRouteRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $routeId = $this->route('routeRate');

        return [
            'origin_branch_id' => [
                'required',
                'integer',
                'exists:branches,id',
            ],

            'destination_branch_id' => [
                'required',
                'integer',
                'exists:branches,id',
            ],

            'base_rate' => [
                'required',
                'numeric',
                'min:0',
            ],

            'included_weight_kg' => [
                'nullable',
                'numeric',
                'gt:0',
            ],

            'included_distance_km' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'extra_weight_rate' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'extra_distance_rate' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'same_day_multiplier' => [
                'nullable',
                'numeric',
                'min:1',
            ],

            'effective_from' => [
                'nullable',
                'date',
            ],

            'effective_to' => [
                'nullable',
                'date',
                'after_or_equal:effective_from',
            ],

            'bidirectional' => [
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

            'route_unique_check' => [
                Rule::unique(
                    'branch_route_rates',
                    'origin_branch_id'
                )
                    ->ignore($routeId),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'route_unique_check' =>
                $this->input('origin_branch_id'),
        ]);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $exists = \Modules\Rate\Models\BranchRouteRate::query()
                ->when(
                    $this->route('routeRate'),
                    fn ($query, $id) =>
                        $query->whereKeyNot($id)
                )
                ->where(
                    'origin_branch_id',
                    $this->input('origin_branch_id')
                )
                ->where(
                    'destination_branch_id',
                    $this->input('destination_branch_id')
                )
                ->exists();

            if ($exists) {
                $validator->errors()->add(
                    'destination_branch_id',
                    'This branch route already has a pricing record.'
                );
            }
        });
    }
}