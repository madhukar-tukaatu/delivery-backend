<?php

declare(strict_types=1);

namespace Modules\Pickup\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class GatewayCreatePickupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pickup_location_id' => [
                'required',
                'integer',
                'min:1',
            ],

            /*
             * This is the STORE's own pickup container.
             *
             * Examples:
             *
             * PR-001
             * PR-002
             * PR-003
             */
            'store_reference' => [
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