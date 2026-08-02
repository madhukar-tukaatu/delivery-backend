<?php

namespace Modules\Merchant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIntegrationSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $payload = $this->input('payload');

        if (is_string($payload)) {
            $decoded = json_decode($payload, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $this->merge($decoded);
            }
        }

        $this->merge([
            'application_number' => $this->route('applicationNumber'),
        ]);
    }

    public function rules(): array
    {
        return [
            'application_number' => [
                'required',
                'string',
                'max:150',
                'regex:/^[A-Za-z0-9._-]+$/',
            ],

            'store' => ['required', 'array'],
            'store.external_store_id' => ['required', 'string', 'max:150'],
            'store.name' => ['required', 'string', 'max:255'],
            'store.url' => ['nullable', 'url', 'max:500'],
            'store.platform' => ['required', 'string', 'max:100'],
            'store.category' => ['nullable', 'string', 'max:150'],
            'store.support_email' => ['nullable', 'email', 'max:255'],
            'store.support_phone' => ['nullable', 'string', 'max:50'],

            'business' => ['required', 'array'],
            'business.name' => ['required', 'string', 'max:255'],
            'business.owner_name' => ['required', 'string', 'max:255'],
            'business.contact_person' => ['nullable', 'string', 'max:255'],
            'business.type' => ['nullable', 'string', 'max:100'],
            'business.pan_vat_number' => ['required', 'string', 'max:100'],
            'business.registration_number' => ['required', 'string', 'max:100'],
            'business.email' => ['required', 'email', 'max:255'],
            'business.phone' => ['required', 'string', 'max:50'],
            'business.alternative_phone' => ['nullable', 'string', 'max:50'],
            'business.registered_address' => ['required', 'string', 'max:1000'],

            'pickup_location' => ['required', 'array'],
            'pickup_location.name' => ['required', 'string', 'max:255'],
            'pickup_location.contact_person' => ['required', 'string', 'max:255'],
            'pickup_location.phone' => ['required', 'string', 'max:50'],
            'pickup_location.country' => ['required', 'string', 'max:100'],
            'pickup_location.province' => ['required', 'string', 'max:100'],
            'pickup_location.district' => ['required', 'string', 'max:100'],
            'pickup_location.city' => ['required', 'string', 'max:120'],
            'pickup_location.area' => ['required', 'string', 'max:120'],
            'pickup_location.street' => ['nullable', 'string', 'max:150'],
            'pickup_location.address' => ['required', 'string', 'max:1000'],
            'pickup_location.landmark' => ['nullable', 'string', 'max:255'],
            'pickup_location.latitude' => ['required', 'numeric', 'between:-90,90'],
            'pickup_location.longitude' => ['required', 'numeric', 'between:-180,180'],
            'pickup_location.opening_time' => ['nullable', 'date_format:H:i'],
            'pickup_location.closing_time' => ['nullable', 'date_format:H:i'],
            'pickup_location.operating_days' => ['nullable', 'array'],
            'pickup_location.operating_days.*' => [
                Rule::in([
                    'sunday',
                    'monday',
                    'tuesday',
                    'wednesday',
                    'thursday',
                    'friday',
                    'saturday',
                ]),
            ],

            'requested_services' => ['required', 'array', 'min:1'],
            'requested_services.*' => [
                'string',
                Rule::in([
                    'delivery_pricing',
                    'quote_creation',
                    'shipment_creation',
                    'tracking',
                    'webhooks',
                    'cod',
                    'returns',
                ]),
            ],

            'callback' => ['required', 'array'],
            'callback.url' => ['required', 'url', 'max:1000'],
            'callback.secret' => ['required', 'string', 'min:32', 'max:500'],

            'terms_accepted' => ['accepted'],

            'documents' => ['required', 'array'],

            'documents.business_registration' => [
                'required',
                'array',
            ],

            'documents.pan_vat' => [
                'required',
                'array',
            ],

            'documents.owner_id' => [
                'required',
                'array',
            ],

            'documents.bank_proof' => [
                'required',
                'array',
            ],

            'documents.office_photo' => [
                'nullable',
                'array',
            ],

            'documents.authorisation_letter' => [
                'nullable',
                'array',
            ],

            'documents.*.url' => [
                'required',
                'url',
                'max:5000',
                'starts_with:https://',
            ],

            'documents.*.original_name' => [
                'required',
                'string',
                'max:255',
            ],

            'documents.*.size_bytes' => [
                'nullable',
                'integer',
                'min:1',
                'max:10485760',
            ],

            'documents.*.sha256' => [
                'nullable',
                'string',
                'regex:/^[a-fA-F0-9]{64}$/',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'payload.required' => 'The payload JSON is required.',
            'documents.required' => 'Merchant verification documents are required.',
            'terms_accepted.accepted' => 'The Tukaatu Express terms must be accepted.',
        ];
    }
}
