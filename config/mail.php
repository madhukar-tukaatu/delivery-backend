<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    */

    'default' => env(
        'MAIL_MAILER',
        'log'
    ),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    */

    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',

            /*
             * For port 465:
             * MAIL_SCHEME=smtps
             */
            'scheme' => env(
                'MAIL_SCHEME'
            ),

            /*
             * Keep MAIL_URL empty unless the entire
             * SMTP connection is configured through one URL.
             */
            'url' => env(
                'MAIL_URL'
            ),

            'host' => env(
                'MAIL_HOST',
                '127.0.0.1'
            ),

            'port' => env(
                'MAIL_PORT',
                2525
            ),

            'username' => env(
                'MAIL_USERNAME'
            ),

            'password' => env(
                'MAIL_PASSWORD'
            ),

            'timeout' => env(
                'MAIL_TIMEOUT',
                30
            ),

            'local_domain' => env(
                'MAIL_EHLO_DOMAIN',
                parse_url(
                    (string) env(
                        'APP_URL',
                        'http://localhost'
                    ),
                    PHP_URL_HOST
                )
            ),
        ],

        'log' => [
            'transport' => 'log',

            'channel' => env(
                'MAIL_LOG_CHANNEL'
            ),
        ],

        'array' => [
            'transport' => 'array',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global From Address
    |--------------------------------------------------------------------------
    */

    'from' => [
        'address' => env(
            'MAIL_FROM_ADDRESS',
            'hello@example.com'
        ),

        'name' => env(
            'MAIL_FROM_NAME',
            env(
                'APP_NAME',
                'Tukaatu Express'
            )
        ),
    ],
];