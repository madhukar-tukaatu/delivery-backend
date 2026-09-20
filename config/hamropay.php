<?php

return [
    'api_base_url'   => env('HAMROPAY_API_BASE_URL'),
    'gateway_url'    => env('HAMROPAY_GATEWAY_URL'),
    'client_id'      => env('HAMROPAY_CLIENT_ID'),
    'client_api_key' => env('HAMROPAY_CLIENT_API_KEY'),
    'secret'         => env('HAMROPAY_SECRET'),
    'merchant_id'    => env('HAMROPAY_MERCHANT_ID'),
    'webhook_secret' => env('HAMROPAY_WEBHOOK_SECRET'),
    'success_url'    => env('HAMROPAY_SUCCESS_URL', env('APP_URL', 'https://tukaatuexpress.com') . '/subscriptions/hamropay/success'),
    'failure_url'    => env('HAMROPAY_FAILURE_URL', env('APP_URL', 'https://tukaatuexpress.com') . '/subscriptions/hamropay/failure'),
    'number_success_url' => env('HAMROPAY_NUMBER_SUCCESS_URL', env('APP_URL', 'https://tukaatuexpress.com') . '/number/hamropay/success'),
    'number_failure_url' => env('HAMROPAY_NUMBER_FAILURE_URL', env('APP_URL', 'https://tukaatuexpress.com') . '/number/hamropay/failure'),
    'marketplace_success_url' => env('HAMROPAY_MARKETPLACE_SUCCESS_URL', env('APP_URL', 'https://tukaatuexpress.com') . '/order-success'),
    'marketplace_failure_url' => env('HAMROPAY_MARKETPLACE_FAILURE_URL', env('APP_URL', 'https://tukaatuexpress.com') . '/checkout'),
    'verify_ssl'     => env('HAMROPAY_VERIFY_SSL', true),
    'registration_base_url'    => env('HAMROPAY_REGISTRATION_BASE_URL', 'https://uatpay-api-rest.hamropatro.com'),
    'registration_api_key'     => env('HAMROPAY_REGISTRATION_API_KEY', ''),
    'registration_app_id'      => env('HAMROPAY_REGISTRATION_APP_ID', ''),
    'registration_authorization' => env('HAMROPAY_REGISTRATION_AUTHORIZATION', ''),
    'registration_service_id'  => env('HAMROPAY_REGISTRATION_SERVICE_ID', 'hp-merchant'),
];
