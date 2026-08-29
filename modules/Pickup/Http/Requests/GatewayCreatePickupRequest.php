<?php

declare(strict_types=1);

namespace Modules\Pickup\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class GatewayCreatePickupRequest
    extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pickup_request_number' => [
                'required',
                'string',
                'max:100',
            ],

            'pickup_location_id' => [
                'required',
                'integer',
                'min:1',
            ],

            'shipment_tracking_numbers' => [
                'required',
                'array',
                'min:1',
                'max:500',
            ],

            'shipment_tracking_numbers.*' => [
                'required',
                'string',
                'max:100',
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