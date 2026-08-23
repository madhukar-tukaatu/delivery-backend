<?php

namespace Modules\Pickup\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddShipmentsToPickupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
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

            'remarks' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }
}