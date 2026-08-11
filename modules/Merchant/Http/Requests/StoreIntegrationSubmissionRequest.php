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
        /*
         * Normal JSON request.
         */
        if ($this->isJson()) {
            $json = $this->json()->all();

            if (is_array($json) && $json !== []) {
                $this->merge($json);
            }
        }

        /*
         * Backward compatibility for:
         *
         * payload = JSON string
         */
        $payload = $this->input('payload');

        if (
            is_string($payload) &&
            trim($payload) !== ''
        ) {
            $decoded = json_decode(
                $payload,
                true
            );

            if (
                json_last_error() === JSON_ERROR_NONE &&
                is_array($decoded)
            ) {
                $this->merge($decoded);
            }
        }

        /*
         * Final fallback for incorrectly sent raw JSON.
         */
        if (
            ! $this->has('store') &&
            trim((string) $this->getContent()) !== ''
        ) {
            $decoded = json_decode(
                $this->getContent(),
                true
            );

            if (
                json_last_error() === JSON_ERROR_NONE &&
                is_array($decoded)
            ) {
                $this->merge($decoded);
            }
        }

        $this->merge([
            'application_number' =>
                $this->route('applicationNumber'),
        ]);
    }

    public function rules(): array
    {
        return [
            /*
             * Application
             */
            'application_number' => [
                'required',
                'string',
                'max:150',
                'regex:/^[A-Za-z0-9._-]+$/',
            ],

            /*
             * Store
             */
            'store' => [
                'required',
                'array',
            ],

            'store.external_store_id' => [
                'required',
                'string',
                'max:150',
            ],

            'store.name' => [
                'required',
                'string',
                'max:255',
            ],

            'store.url' => [
                'nullable',
                'url',
                'max:500',
            ],

            'store.platform' => [
                'required',
                'string',
                'max:100',
            ],

            'store.category' => [
                'nullable',
                'string',
                'max:150',
            ],

            'store.support_email' => [
                'nullable',
                'email',
                'max:255',
            ],

            'store.support_phone' => [
                'nullable',
                'string',
                'max:50',
            ],

            /*
             * Business
             */
            'business' => [
                'required',
                'array',
            ],

            'business.name' => [
                'required',
                'string',
                'max:255',
            ],

            'business.owner_name' => [
                'required',
                'string',
                'max:255',
            ],

            'business.contact_person' => [
                'nullable',
                'string',
                'max:255',
            ],

            'business.type' => [
                'nullable',
                'string',
                'max:100',
            ],

            'business.pan_vat_number' => [
                'required',
                'string',
                'max:100',
            ],

            'business.registration_number' => [
                'required',
                'string',
                'max:100',
            ],

            'business.email' => [
                'required',
                'email',
                'max:255',
            ],

            'business.phone' => [
                'required',
                'string',
                'max:50',
            ],

            'business.alternative_phone' => [
                'nullable',
                'string',
                'max:50',
            ],

            'business.registered_address' => [
                'required',
                'string',
                'max:1000',
            ],

            /*
             * Pickup location
             */
            'pickup_location' => [
                'required',
                'array',
            ],

            'pickup_location.name' => [
                'required',
                'string',
                'max:255',
            ],

            'pickup_location.contact_person' => [
                'nullable',
                'string',
                'max:255',
            ],

            'pickup_location.phone' => [
                'required',
                'string',
                'max:50',
            ],

            'pickup_location.country' => [
                'nullable',
                'string',
                'max:100',
            ],

            'pickup_location.province' => [
                'nullable',
                'string',
                'max:100',
            ],

            'pickup_location.district' => [
                'nullable',
                'string',
                'max:100',
            ],

            'pickup_location.city' => [
                'nullable',
                'string',
                'max:120',
            ],

            'pickup_location.area' => [
                'nullable',
                'string',
                'max:120',
            ],

            'pickup_location.street' => [
                'nullable',
                'string',
                'max:150',
            ],

            'pickup_location.address' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'pickup_location.landmark' => [
                'nullable',
                'string',
                'max:255',
            ],

            'pickup_location.latitude' => [
                'required',
                'numeric',
                'between:-90,90',
            ],

            'pickup_location.longitude' => [
                'required',
                'numeric',
                'between:-180,180',
            ],

            'pickup_location.opening_time' => [
                'nullable',
                'date_format:H:i',
            ],

            'pickup_location.closing_time' => [
                'nullable',
                'date_format:H:i',
            ],

            'pickup_location.operating_days' => [
                'nullable',
                'array',
            ],

            'pickup_location.operating_days.*' => [
                'nullable',
                'string',
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

            /*
             * Documents
             *
             * Every document type is an array.
             *
             * Therefore:
             *
             * owner_id => one or many
             * pan_vat => one or many
             * etc.
             */
            'documents' => [
                'required',
                'array',
            ],

            /*
             * Required document groups
             */
            'documents.business_registration' => [
                'required',
                'array',
                'min:1',
            ],

            'documents.pan_vat' => [
                'required',
                'array',
                'min:1',
            ],

            'documents.owner_id' => [
                'required',
                'array',
                'min:1',
            ],

            'documents.bank_proof' => [
                'required',
                'array',
                'min:1',
            ],

            /*
             * Optional document groups
             */
            'documents.office_photo' => [
                'nullable',
                'array',
            ],

            'documents.authorisation_letter' => [
                'nullable',
                'array',
            ],

            'documents.additional_documents' => [
                'nullable',
                'array',
            ],

            /*
             * Every individual document.
             */
            'documents.business_registration.*' => [
                'required',
                'array',
            ],

            'documents.pan_vat.*' => [
                'required',
                'array',
            ],

            'documents.owner_id.*' => [
                'required',
                'array',
            ],

            'documents.bank_proof.*' => [
                'required',
                'array',
            ],

            'documents.office_photo.*' => [
                'required',
                'array',
            ],

            'documents.authorisation_letter.*' => [
                'required',
                'array',
            ],

            'documents.additional_documents.*' => [
                'required',
                'array',
            ],

            /*
             * Common document fields.
             */
            'documents.*.*.url' => [
                'required',
                'url',
                'starts_with:https://',
                'max:5000',
            ],

            'documents.*.*.original_name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'documents.*.*.size_bytes' => [
                'nullable',
                'integer',
                'min:1',
                'max:10485760',
            ],

            'documents.*.*.sha256' => [
                'nullable',
                'string',
                'regex:/^[a-fA-F0-9]{64}$/',
            ],

            /*
             * Optional metadata for additional documents.
             */
            'documents.additional_documents.*.name' => [
                'nullable',
                'string',
                'max:255',
            ],

            'documents.additional_documents.*.description' => [
                'nullable',
                'string',
                'max:1000',
            ],

            /*
             * Services
             */
            'requested_services' => [
                'required',
                'array',
                'min:1',
            ],

            'requested_services.*' => [
                'string',
                Rule::in([
                    'delivery_pricing',
                    'quote_creation',
                    'shipment_creation',
                    'tracking',
                    'webhooks',
                    'pod',
                    'returns',
                ]),
            ],

            /*
             * Callback
             */
            'callback' => [
                'required',
                'array',
            ],

            'callback.url' => [
                'required',
                'url',
                'max:1000',
            ],

            'callback.secret' => [
                'required',
                'string',
                'min:32',
                'max:500',
            ],

            /*
             * Terms
             */
            'terms_accepted' => [
                'accepted',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'terms_accepted.accepted' =>
                'The Tukaatu Express terms must be accepted.',

            'documents.required' =>
                'Store verification documents are required.',

            'documents.business_registration.required' =>
                'At least one business registration document is required.',

            'documents.pan_vat.required' =>
                'At least one PAN/VAT document is required.',

            'documents.owner_id.required' =>
                'At least one owner identification document is required.',

            'documents.bank_proof.required' =>
                'At least one bank proof document is required.',

            'documents.*.*.url.starts_with' =>
                'Every document URL must use HTTPS.',
        ];
    }
}