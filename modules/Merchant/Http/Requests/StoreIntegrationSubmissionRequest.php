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

        /*
         * Always use the application number from the route.
         */
        $this->merge([
            'application_number' =>
                $this->route('applicationNumber'),
        ]);
    }

    public function rules(): array
    {
        return [

            /*
             * =========================================================
             * Application
             * =========================================================
             */

            'application_number' => [
                'required',
                'string',
                'max:150',
                'regex:/^[A-Za-z0-9._-]+$/',
            ],

            /*
             * =========================================================
             * Store
             * =========================================================
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
             * =========================================================
             * Business
             * =========================================================
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
                'nullable',
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
             * =========================================================
             * Pickup location
             * =========================================================
             */

            'pickup_location' => [
                'required',
                'array',
            ],

            'pickup_location.name' => [
                'nullable',
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
             * =========================================================
             * Documents
             * =========================================================
             *
             * REQUIRED:
             *
             *   pan_vat
             *   owner_id
             *
             * OPTIONAL:
             *
             *   business_registration
             *   bank_proof
             *   office_photo
             *   authorisation_letter
             *   additional_documents
             *
             * Every group can contain multiple documents.
             */

            'documents' => [
                'required',
                'array',
            ],

            /*
             * ---------------------------------------------------------
             * Required document groups
             * ---------------------------------------------------------
             */

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

            /*
             * ---------------------------------------------------------
             * Optional document groups
             * ---------------------------------------------------------
             */

            'documents.business_registration' => [
                'nullable',
                'array',
            ],

            'documents.bank_proof' => [
                'nullable',
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

            'documents.additional_documents' => [
                'nullable',
                'array',
            ],

            /*
             * ---------------------------------------------------------
             * Individual documents
             * ---------------------------------------------------------
             */

            'documents.pan_vat.*' => [
                'required',
                'array',
            ],

            'documents.owner_id.*' => [
                'required',
                'array',
            ],

            'documents.business_registration.*' => [
                'nullable',
                'array',
            ],

            'documents.bank_proof.*' => [
                'nullable',
                'array',
            ],

            'documents.office_photo.*' => [
                'nullable',
                'array',
            ],

            'documents.authorisation_letter.*' => [
                'nullable',
                'array',
            ],

            'documents.additional_documents.*' => [
                'nullable',
                'array',
            ],

            /*
             * ---------------------------------------------------------
             * Common document fields
             * ---------------------------------------------------------
             *
             * URL is required for an actual document.
             *
             * The document name is NOT required.
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
             * ---------------------------------------------------------
             * Additional document metadata
             * ---------------------------------------------------------
             *
             * These are optional.
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
             * =========================================================
             * Services
             * =========================================================
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
             * =========================================================
             * Callback
             * =========================================================
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
             * =========================================================
             * Terms
             * =========================================================
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

            /*
             * Required documents.
             */
            'documents.required' =>
                'Store verification documents are required.',

            'documents.pan_vat.required' =>
                'At least one PAN/VAT document is required.',

            'documents.pan_vat.min' =>
                'At least one PAN/VAT document is required.',

            'documents.owner_id.required' =>
                'At least one owner identification document is required.',

            'documents.owner_id.min' =>
                'At least one owner identification document is required.',

            /*
             * Individual document validation.
             */
            'documents.pan_vat.*.required' =>
                'Each PAN/VAT document must contain document information.',

            'documents.owner_id.*.required' =>
                'Each owner identification document must contain document information.',

            'documents.*.*.url.required' =>
                'Every document must contain a URL.',

            'documents.*.*.url.url' =>
                'Every document URL must be a valid URL.',

            'documents.*.*.url.starts_with' =>
                'Every document URL must use HTTPS.',

            'documents.*.*.size_bytes.max' =>
                'Each document must not exceed 10 MB.',

            'documents.*.*.sha256.regex' =>
                'The document SHA-256 checksum is invalid.',
        ];
    }
}