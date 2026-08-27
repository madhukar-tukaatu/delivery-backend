<?php

declare(strict_types=1);

namespace Modules\Pickup\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class GatewayCreatePickupRequest extends FormRequest
{
    public function authorize(): bool
    {
        /*
         * Gateway authentication is handled by middleware.
         *
         * The middleware places merchant_id into request attributes.
         */
        return (int) $this->request->get('merchant_id') > 0
            || (int) $this->attributes->get('merchant_id') > 0;
    }

    public function rules(): array
    {
        return [

            /*
            |--------------------------------------------------------------------------
            | Pickup Location
            |--------------------------------------------------------------------------
            */

            'pickup_location_id' => [
                'required',
                'integer',
                'min:1',
            ],

            /*
            |--------------------------------------------------------------------------
            | Shipments
            |--------------------------------------------------------------------------
            |
            | A single pickup request may contain multiple shipments.
            |
            */

            'shipments' => [
                'required',
                'array',
                'min:1',
                'max:200',
            ],

            'shipments.*.tracking_number' => [
                'required',
                'string',
                'max:100',
            ],

            /*
            |--------------------------------------------------------------------------
            | Optional
            |--------------------------------------------------------------------------
            */

            'remarks' => [
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }
}