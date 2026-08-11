<?php

use App\Http\Middleware\ApiCorsMiddleware;
use App\Http\Middleware\AuthenticateMarketplaceApiKey;
use App\Http\Middleware\BranchScopeMiddleware;
use App\Http\Middleware\CheckRoutePermission;
use App\Http\Middleware\GatewayAuthMiddleware;
use App\Http\Middleware\PermissionMiddleware;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Modules\Merchant\Http\Middleware\AuthenticateMerchantApiKey;
use Modules\Merchant\Http\Middleware\AuthenticateStoreIntegrationToken;

return Application::configure(
    basePath: dirname(__DIR__)
)
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )

    ->withBroadcasting(
        __DIR__ . '/../routes/channels.php',
        [
            'prefix' => 'api',
            'middleware' => [
                'api',
                'auth:sanctum',
            ],
        ]
    )

    ->withMiddleware(function (Middleware $middleware): void {

        /*
        |--------------------------------------------------------------------------
        | API CORS
        |--------------------------------------------------------------------------
        |
        | ApiCorsMiddleware handles:
        |
        | 1. Normal API CORS
        | 2. Dynamic public-store origins
        | 3. Public pricing estimate CORS
        |
        */

        $middleware->api(
            prepend: [
                ApiCorsMiddleware::class,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Middleware aliases
        |--------------------------------------------------------------------------
        */

        $middleware->alias([

            /*
            |--------------------------------------------------------------------------
            | IMPORTANT
            |--------------------------------------------------------------------------
            | Some existing routes still use:
            |
            |     'api.cors'
            |
            | Register the alias so Laravel can resolve it.
            */

            'api.cors' =>
                ApiCorsMiddleware::class,

            'role' =>
                RoleMiddleware::class,

            'gateway.auth' =>
                GatewayAuthMiddleware::class,

            'permission' =>
                PermissionMiddleware::class,

            'branch.scope' =>
                BranchScopeMiddleware::class,

            'route.permission' =>
                CheckRoutePermission::class,

            'merchant.api-key' =>
                AuthenticateMerchantApiKey::class,

            'marketplace.api-key' =>
                AuthenticateMarketplaceApiKey::class,

            'store.integration.token' =>
                AuthenticateStoreIntegrationToken::class,
        ]);
    })

    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })

    ->create();