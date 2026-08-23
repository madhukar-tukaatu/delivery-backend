<?php

namespace Modules\Pickup\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePickupRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [

            'pickup_location_id' => [
                'nullable',
                'integer',
            ],

            'shipment_ids' => [
                'required',
                'array',
                'min:1',
                'max:100',
            ],

            'shipment_ids.*' => [
                'required',
                'integer',
                'distinct',
                'exists:shipments,id',
            ],

            'preferred_pickup_at' => [
                'nullable',
                'date',
            ],

            'remarks' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }
}