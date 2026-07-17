<?php

use App\Http\Middleware\BranchScopeMiddleware;
use App\Http\Middleware\CheckRoutePermission;
use App\Http\Middleware\GatewayAuthMiddleware;
use App\Http\Middleware\PermissionMiddleware;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Modules\Merchant\Http\Middleware\AuthenticateMerchantApiKey;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [HandleCors::class]);
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'gateway.auth' => GatewayAuthMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'branch.scope' => BranchScopeMiddleware::class,
            'route.permission' => CheckRoutePermission::class,
            'merchant.api-key' => AuthenticateMerchantApiKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Centralized exception rendering can be added here.
    })
    ->create();
