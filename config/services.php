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
];