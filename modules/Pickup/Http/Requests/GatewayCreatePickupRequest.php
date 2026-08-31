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
            /*
             * Physical pickup location/store location.
             */
            'pickup_location_id' => [
                'required',
                'integer',
                'min:1',
            ],

            /*
             * Store's own pickup/container reference.
             *
             * Example:
             *
             * PR-001
             * PR-002
             * STORE-20260831-001
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

    protected function prepareForValidation(): void
    {
        if ($this->has('store_reference')) {
            $this->merge([
                'store_reference' => trim(
                    (string) $this->input('store_reference')
                ),
            ]);
        }
    }
}