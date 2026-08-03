<?php

namespace App\Providers;

use App\Documentation\MixedMerchantSecurityStrategy;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Routing\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class ApiDocumentationServiceProvider extends ServiceProvider
{
    /**
     * Disable Scramble's default documentation routes:
     *
     * /docs/api
     * /docs/api.json
     */
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
    | Authentication:
    |
    | Authorization: Bearer {ADMIN_ACCESS_TOKEN}
    |
    */

    private function registerAdminPanelApi(): void
    {
        Scramble::registerApi(
            'admin-panel',
            $this->makeApiConfig(
                title: 'Tukaatu Express Admin API',
                description:
                    'Internal Tukaatu Express administration API.',
                securityStrategy: [
                    MiddlewareAuthSecurityStrategy::class,
                    [
                        'middleware' => [
                            'auth',
                            'auth:*',
                        ],

                        'scheme' => SecurityScheme::http(
                            'bearer'
                        ),
                    ],
                ]
            )
        )
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
    | Authentication:
    |
    | Authorization: Bearer {STORE_SUBMISSION_TOKEN}
    |
    | Request format:
    |
    | Content-Type: application/json
    |
    */

    private function registerStoreIntegrationApi(): void
    {
        Scramble::registerApi(
            'store-integration',
            $this->makeApiConfig(
                title: 'Tukaatu Store Integration API',
                description:
                    'Store Manager integration API for merchant application submission, approval and connection.',
                securityStrategy: [
                    MiddlewareAuthSecurityStrategy::class,
                    [
                        /*
                         * This must match the middleware added
                         * to the Store Integration routes.
                         */
                        'middleware' => [
                            'store.integration.token',

                            /*
                             * Also supports the middleware class
                             * when Laravel returns the resolved
                             * class name instead of its alias.
                             */
                            '*AuthenticateStoreIntegrationToken',
                        ],

                        'scheme' => SecurityScheme::http(
                            'bearer'
                        ),
                    ],
                ]
            )
        )
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
    | Authentication:
    |
    | No authentication header.
    |
    | The public merchant form continues using direct file uploads.
    | Scramble detects Laravel file validation and documents the body as:
    |
    | Content-Type: multipart/form-data
    |
    */

    private function registerPublicMerchantApi(): void
    {
        Scramble::registerApi(
            'public-merchant',
            $this->makeApiConfig(
                title: 'Tukaatu Public Merchant API',
                description:
                    'Public Tukaatu Express merchant registration and onboarding API.',
                securityStrategy: null
            )
        )
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
    | Merchant and Pricing Engine API
    |--------------------------------------------------------------------------
    |
    | This combined documentation contains two authentication types.
    |
    | Store Integration endpoints:
    |
    | Authorization: Bearer {STORE_SUBMISSION_TOKEN}
    |
    | Approved merchant operational endpoints:
    |
    | X-Tukaatu-Api-Key: {MERCHANT_API_KEY}
    |
    | MixedMerchantSecurityStrategy automatically selects the correct
    | header based on each route's middleware.
    |
    */

    private function registerMerchantPricingApi(): void
    {
        Scramble::registerApi(
            'merchant-pricing',
            $this->makeApiConfig(
                title: 'Tukaatu Merchant and Pricing API',
                description:
                    'Merchant integration, pricing, quotation, shipment and tracking API.',
                securityStrategy:
                    MixedMerchantSecurityStrategy::class
            )
        )
            ->routes(function (Route $route): bool {
                return $this->matchesAnyPrefix(
                    $route,
                    [
                        /*
                         * Store Manager connection/application
                         */
                        'api/v1/store-integrations',

                        /*
                         * General pricing routes
                         */
                        'api/v1/pricing',
                        'api/v1/rates',
                        'api/v1/quotes',

                        /*
                         * Merchant pricing routes
                         */
                        'api/v1/merchant/pricing',
                        'api/v1/merchant/rates',
                        'api/v1/merchant/quotes',

                        /*
                         * Merchant operational routes
                         */
                        'api/v1/merchant/shipments',
                        'api/v1/merchant/tracking',
                        'api/v1/merchant/webhooks',

                        /*
                         * Alternative merchant-api prefix
                         */
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

    /*
    |--------------------------------------------------------------------------
    | Common API configuration
    |--------------------------------------------------------------------------
    */

    private function makeApiConfig(
        string $title,
        string $description,
        array|string|null $securityStrategy
    ): array {
        return [
            'api_path' => 'api/v1',

            'info' => [
                'version' => '1.0.0',
                'description' => $description,
            ],

            'ui' => [
                'title' => $title,
            ],

            'renderer' => 'scalar',

            /*
             * Automatically works locally and live based on APP_URL.
             *
             * Local example:
             * http://localhost:8081/api/v1
             *
             * Live example:
             * https://api.yourdomain.com/api/v1
             */
            'servers' => [
                'Current environment' =>
                    rtrim(
                        (string) config('app.url'),
                        '/'
                    ) . '/api/v1',
            ],

            'security_strategy' => $securityStrategy,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Route matching helpers
    |--------------------------------------------------------------------------
    */

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