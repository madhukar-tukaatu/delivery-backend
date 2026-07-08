<?php

use Illuminate\Support\Facades\Route;
use Modules\Webhook\Http\Controllers\WebhookController;

/*
|--------------------------------------------------------------------------
| Admin Webhook Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Webhook Logs
            |--------------------------------------------------------------------------
            | Auto permissions:
            | webhooks.view
            | webhooks.retry
            | webhooks.manage
            */

            Route::get('webhook-logs', [WebhookController::class, 'logs'])
                ->name('webhook-logs.index');

            Route::post('webhook-logs/{log}/retry', [WebhookController::class, 'retry'])
                ->name('webhook-logs.retry');

            Route::post('webhooks/test', [WebhookController::class, 'test'])
                ->name('webhooks.test');
        });
    });


/*
|--------------------------------------------------------------------------
| Merchant Webhook Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/merchant')
    ->name('merchant.')
    ->middleware(['auth:sanctum', 'role:merchant', 'branch.scope'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Merchant Webhook Logs
            |--------------------------------------------------------------------------
            | Auto permissions:
            | merchant.webhooks
            | webhooks.view
            */

            Route::get('webhook-logs', [WebhookController::class, 'logs'])
                ->name('webhook-logs.index');
        });
    });
