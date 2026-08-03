<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class ApiDocumentationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Scramble::ignoreDefaultRoutes();
    }

    public function boot(): void
    {
        $this->registerAdminPanelApi();
        $this->registerStoreIntegrationApi();
        $this->registerPublicMerchantApi();
        $this->registerMerchantPricingApi();
    }

    /*
    |--------------------------------------------------------------------------
    | Admin Panel API
    |--------------------------------------------------------------------------
    |
    | Header:
    | Authorization: Bearer {ADMIN_TOKEN}
    |
    */

    private function registerAdminPanelApi(): void
    {
        Scramble::registerApi('admin-panel', [
            'api_path' => 'api/v1',

            'info' => [
                'title' => 'Tukaatu Express Admin API',
                'version' => '1.0.0',
                'description' =>
                    'Internal Tukaatu Express administration API.',
            ],

            'renderer' => 'scalar',

            'security_strategy' => [
                MiddlewareAuthSecurityStrategy::class,
                [
                    /*
                     * Matches auth, auth:sanctum,
                     * auth:api and similar middleware.
                     */
                    'middleware' => [
                        'auth',
                        'auth:*',
                    ],

                    'scheme' =>
                        SecurityScheme::http('bearer'),
                ],
            ],
        ])
            ->routes(function (Route $route): bool {
                return $this->matchesAnyPrefix(
                    $route,
                    [
                        'api/v1/admin',
                    ]
                );
            })
            ->expose(
                ui: '/docs/admin',
                document: '/docs/admin/openapi.json'
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Store Integration API
    |--------------------------------------------------------------------------
    |
    | Header:
    | Authorization: Bearer {STORE_SUBMISSION_TOKEN}
    |
    */

    private function registerStoreIntegrationApi(): void
    {
        Scramble::registerApi('store-integration', [
            'api_path' => 'api/v1',

            'info' => [
                'title' =>
                    'Tukaatu Store Integration API',

                'version' => '1.0.0',

                'description' =>
                    'Store Manager merchant application and integration API.',
            ],

            'renderer' => 'scalar',

            'security_strategy' => [
                MiddlewareAuthSecurityStrategy::class,
                [
                    /*
                     * Must exactly match the alias in:
                     * bootstrap/app.php
                     */
                    'middleware' => [
                        'store.integration.token',
                    ],

                    'scheme' =>
                        SecurityScheme::http('bearer'),
                ],
            ],
        ])
            ->routes(function (Route $route): bool {
                return $this->matchesAnyPrefix(
                    $route,
                    [
                        'api/v1/store-integrations',
                    ]
                );
            })
            ->expose(
                ui: '/docs/store-integration',
                document:
                    '/docs/store-integration/openapi.json'
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Public Merchant API
    |--------------------------------------------------------------------------
    |
    | No authentication header.
    |
    | Direct document uploads should automatically use:
    | multipart/form-data
    |
    */

    private function registerPublicMerchantApi(): void
    {
        Scramble::registerApi('public-merchant', [
            'api_path' => 'api/v1',

            'info' => [
                'title' =>
                    'Tukaatu Public Merchant API',

                'version' => '1.0.0',

                'description' =>
                    'Public merchant registration and onboarding API.',
            ],

            'renderer' => 'scalar',

            /*
             * Public API: no authentication.
             */
            'security_strategy' => null,
        ])
            ->routes(function (Route $route): bool {
                $uri = $this->routeUri($route);

                return in_array(
                    $uri,
                    [
                        'api/v1/merchant/signup',
                    ],
                    true
                ) || $this->matchesAnyPrefix(
                    $route,
                    [
                        'api/v1/merchant/public',
                    ]
                );
            })
            ->expose(
                ui: '/docs/public-merchant',
                document:
                    '/docs/public-merchant/openapi.json'
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Merchant Pricing API
    |--------------------------------------------------------------------------
    |
    | Header:
    | X-Tukaatu-Api-Key: {MERCHANT_API_KEY}
    |
    | Important:
    | The real middleware alias in bootstrap/app.php is:
    | merchant.api-key
    |
    */

    private function registerMerchantPricingApi(): void
    {
        Scramble::registerApi('merchant-pricing', [
            'api_path' => 'api/v1',

            'info' => [
                'title' =>
                    'Tukaatu Merchant and Pricing API',

                'version' => '1.0.0',

                'description' =>
                    'Merchant pricing, quotation, shipment and tracking API.',
            ],

            'renderer' => 'scalar',

            'security_strategy' => [
                MiddlewareAuthSecurityStrategy::class,
                [
                    /*
                     * Exact alias from bootstrap/app.php:
                     *
                     * 'merchant.api-key' =>
                     *     AuthenticateMerchantApiKey::class
                     */
                    'middleware' => [
                        'merchant.api-key',
                    ],

                    'scheme' =>
                        SecurityScheme::apiKey(
                            'header',
                            'X-Tukaatu-Api-Key'
                        ),
                ],
            ],
        ])
            ->routes(function (Route $route): bool {
                return $this->matchesAnyPrefix(
                    $route,
                    [
                        'api/v1/pricing',
                        'api/v1/rates',
                        'api/v1/quotes',

                        'api/v1/merchant/pricing',
                        'api/v1/merchant/rates',
                        'api/v1/merchant/quotes',

                        'api/v1/merchant/shipments',
                        'api/v1/merchant/tracking',
                        'api/v1/merchant/webhooks',

                        'api/v1/merchant-api/pricing',
                        'api/v1/merchant-api/rates',
                        'api/v1/merchant-api/quotes',
                        'api/v1/merchant-api/shipments',
                        'api/v1/merchant-api/tracking',
                        'api/v1/merchant-api/webhooks',
                    ]
                );
            })
            ->expose(
                ui: '/docs/merchant-pricing',
                document:
                    '/docs/merchant-pricing/openapi.json'
            );
    }

    private function matchesAnyPrefix(
        Route $route,
        array $prefixes
    ): bool {
        $uri = $this->routeUri($route);

        foreach ($prefixes as $prefix) {
            $prefix = trim(
                (string) $prefix,
                '/'
            );

            if (
                $uri === $prefix ||
                Str::startsWith(
                    $uri,
                    $prefix . '/'
                )
            ) {
                return true;
            }
        }

        return false;
    }

    private function routeUri(Route $route): string
    {
        return trim(
            $route->uri(),
            '/'
        );
    }
}