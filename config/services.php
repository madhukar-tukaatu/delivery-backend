<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Existing service configurations
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Store Manager Integration
    |--------------------------------------------------------------------------
    */

    'store_manager' => [
        /*
         * Local shared token.
         *
         * The Store backend must send:
         * Authorization: Bearer store-integration-local-token
         */
        'submission_token' => 'store-integration-local-token',

        /*
         * Store downloaded documents locally for now.
         *
         * Change this to "s3" later after AWS is configured.
         */
        'document_disk' => 'public',

        /*
         * During local development, allow documents from any HTTPS host.
         *
         * Set this to true in production and add allowed hosts below.
         */
        'enforce_document_host_allowlist' => false,

        /*
         * Not required during local development because enforcement is false.
         */
        'allowed_document_hosts' => [
            // 'store-bucket.s3.ap-south-1.amazonaws.com',
            // 'documents.yourstore.com',
        ],

        /*
         * Maximum downloaded document size.
         */
        'maximum_document_size' => 10 * 1024 * 1024,

        /*
         * Remote download timeout.
         */
        'document_download_timeout' => 60,

        /*
         * POD online payment session integration. The Store Manager team
         * implements these endpoints; keep the URL and shared secret in env.
         */
        'payment' => [
            'enabled' => (bool) env('STORE_MANAGER_PAYMENT_ENABLED', false),
            'base_url' => env('STORE_MANAGER_PAYMENT_BASE_URL'),
            'integration_id' => env('STORE_MANAGER_PAYMENT_INTEGRATION_ID', 'tukaatu-express'),
            'shared_secret' => env('STORE_MANAGER_PAYMENT_INTEGRATION_SECRET'),
            'create_path' => env('STORE_MANAGER_PAYMENT_CREATE_PATH', '/api/v1/integrations/tukaatu-express/pod-payment-sessions'),
            'status_path' => env('STORE_MANAGER_PAYMENT_STATUS_PATH', '/api/v1/integrations/tukaatu-express/pod-payment-sessions/{payment_session_id}'),
            'timeout' => (int) env('STORE_MANAGER_PAYMENT_TIMEOUT', 20),
            'webhook_tolerance' => (int) env('STORE_MANAGER_PAYMENT_WEBHOOK_TOLERANCE', 300),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tukaatu Marketplace API (Express -> Tukaatu outbound)
    |--------------------------------------------------------------------------
    | Doorstep POD QR: Express creates a pending session, then POSTs to
    | Tukaatu /api/v1/gateway/payments/pod-qr with X-Tukaatu-Key/Secret.
    | Tukaatu owns HamroPay createSession and callbacks Express.
    | Doorstep POD uses only the marketplace row (api_base_url, api_key, api_secret).
    | EXPRESS_TUKAATU_API_KEY is not used for rider QR.
    */
    'tukaatu' => [
        'api_base_url' => env('TUKAATU_API_BASE_URL', 'https://api.tukaatu.com'),
        'api_key' => env('EXPRESS_TUKAATU_API_KEY', env('TUKAATU_API_KEY')),
        'api_secret' => env('EXPRESS_TUKAATU_API_SECRET', env('TUKAATU_API_SECRET')),
        'callback_secret' => env('TUKAATU_CALLBACK_SECRET'),
        // When a callback secret is set and X-Tukaatu-Signature is missing: false = accept (warning), true = 401.
        'callback_require_signature' => filter_var(env('TUKAATU_CALLBACK_REQUIRE_SIGNATURE', false), FILTER_VALIDATE_BOOLEAN),
        'pod_payment_request_path' => env('TUKAATU_POD_PAYMENT_REQUEST_PATH', '/api/v1/gateway/payments/pod-qr'),
        'timeout' => (int) env('TUKAATU_API_TIMEOUT', 20),
        'webhook_tolerance' => (int) env('TUKAATU_CALLBACK_TOLERANCE', 300),
        'verify_ssl' => filter_var(env('TUKAATU_API_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
    ],

];
