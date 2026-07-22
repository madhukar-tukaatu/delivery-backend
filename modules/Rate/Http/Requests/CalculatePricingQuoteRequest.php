<?php

namespace Modules\Rate\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CalculatePricingQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
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

            'weight_kg' => [
                'required',
                'numeric',
                'gt:0',
                'max:10000',
            ],

            'distance_km' => [
                'required',
                'numeric',
                'min:0',
                'max:10000',
            ],

            'packet_count' => [
                'nullable',
                'integer',
                'min:1',
                'max:100000',
            ],

            'is_fragile' => [
                'nullable',
                'boolean',
            ],

            'is_same_day' => [
                'nullable',
                'boolean',
            ],

            'requested_at' => [
                'nullable',
                'date',
            ],

            'save_quote' => [
                'nullable',
                'boolean',
            ],
        ];
    }
}