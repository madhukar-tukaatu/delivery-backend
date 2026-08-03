<?php

namespace App\Documentation;

use Dedoc\Scramble\Configuration\OperationTransformers;
use Dedoc\Scramble\Configuration\SecurityDocumentationContext;
use Dedoc\Scramble\Contracts\SecurityDocumentationStrategy;
use Dedoc\Scramble\GeneratorConfig;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\SecurityRequirement;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

class MixedMerchantSecurityStrategy implements
    SecurityDocumentationStrategy
{
    /**
     * Middleware protecting Store Manager submission endpoints.
     */
    private const STORE_INTEGRATION_MIDDLEWARE = [
        'store.integration.token',
        '*AuthenticateStoreIntegrationToken',
    ];

    /**
     * Middleware protecting pricing, shipment and tracking endpoints.
     *
     * Change merchant.api.key here when your real middleware alias
     * uses another name.
     */
    private const MERCHANT_API_KEY_MIDDLEWARE = [
        'merchant.api.key',
        '*MerchantApiKeyGuard',
    ];

    public function configure(
        SecurityDocumentationContext $context
    ): GeneratorConfig {
        return $context->config
            ->withDocumentTransformers(
                function (OpenApi $openApi): void {
                    /*
                     * Store integration bearer token:
                     *
                     * Authorization: Bearer {TOKEN}
                     */
                    $openApi->components->addSecurityScheme(
                        'storeIntegrationBearer',
                        SecurityScheme::http('bearer')
                    );

                    /*
                     * Approved merchant API key:
                     *
                     * X-Tukaatu-Api-Key: {API_KEY}
                     */
                    $openApi->components->addSecurityScheme(
                        'merchantApiKey',
                        SecurityScheme::apiKey(
                            'header',
                            'X-Tukaatu-Api-Key'
                        )
                    );
                }
            )
            ->withOperationTransformers(
                function (
                    OperationTransformers $transformers
                ): void {
                    $transformers->prepend(
                        function (
                            Operation $operation,
                            RouteInfo $routeInfo
                        ): void {
                            $route =
                                $routeInfo->route;

                            /*
                             * Store integration route:
                             *
                             * Authorization: Bearer token
                             */
                            if (
                                $this->routeHasMiddleware(
                                    $route,
                                    self::STORE_INTEGRATION_MIDDLEWARE
                                )
                            ) {
                                $operation->security = [
                                    new SecurityRequirement(
                                        'storeIntegrationBearer'
                                    ),
                                ];

                                return;
                            }

                            /*
                             * Approved merchant route:
                             *
                             * X-Tukaatu-Api-Key
                             */
                            if (
                                $this->routeHasMiddleware(
                                    $route,
                                    self::MERCHANT_API_KEY_MIDDLEWARE
                                )
                            ) {
                                $operation->security = [
                                    new SecurityRequirement(
                                        'merchantApiKey'
                                    ),
                                ];

                                return;
                            }

                            /*
                             * A matching documentation route without
                             * either middleware is shown as public.
                             */
                            $operation->security = [];
                        }
                    );
                }
            );
    }

    private function routeHasMiddleware(
        Route $route,
        array $patterns
    ): bool {
        return collect(
            $route->gatherMiddleware()
        )->contains(
            function (
                string $middleware
            ) use ($patterns): bool {
                return Str::is(
                    $patterns,
                    $middleware
                );
            }
        );
    }
}