<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Paths
    |--------------------------------------------------------------------------
    */

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'broadcasting/auth',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Methods
    |--------------------------------------------------------------------------
    */

    'allowed_methods' => [
        '*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins
    |--------------------------------------------------------------------------
    |
    | These are the trusted origins for the normal API.
    |
    | DO NOT add merchant storefront domains here.
    |
    | The public pricing estimate endpoint has its own dynamic
    | CORS middleware.
    |
    */

    'allowed_origins' => [
        'https://tukaatuexpress.com',
        'https://www.tukaatuexpress.com',

        'https://tukaatu.com',

        'https://fca.com.np',

        'https://api.tukaatu.com',
        'https://api.fca.com.np',

        'http://localhost:3000',
        'http://localhost:3001',
        'http://localhost:3002',
        'http://localhost:3003',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Origin Patterns
    |--------------------------------------------------------------------------
    |
    | Keep empty.
    |
    | We are NOT using a wildcard pattern here because the public
    | pricing endpoint is handled by ApiCorsMiddleware.
    |
    */

    'allowed_origins_patterns' => [],

    /*
    |--------------------------------------------------------------------------
    | Allowed Headers
    |--------------------------------------------------------------------------
    */

    'allowed_headers' => [
        '*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Exposed Headers
    |--------------------------------------------------------------------------
    */

    'exposed_headers' => [],

    /*
    |--------------------------------------------------------------------------
    | Max Age
    |--------------------------------------------------------------------------
    */

    'max_age' => 86400,

    /*
    |--------------------------------------------------------------------------
    | Supports Credentials
    |--------------------------------------------------------------------------
    |
    | Normal authenticated APIs may use credentials where needed.
    |
    | The public pricing endpoint does NOT use credentials.
    |
    */

    'supports_credentials' => true,

];