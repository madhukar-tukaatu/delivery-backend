<?php

use Illuminate\Support\Facades\Route;
use Modules\POD\Http\Controllers\PodController;
use Modules\POD\Http\Controllers\StoreManagerPaymentWebhookController;

/*
|--------------------------------------------------------------------------
| Store Manager payment webhook
|--------------------------------------------------------------------------
| This endpoint is intentionally outside authenticated staff/admin routes.
| The controller verifies the signed raw request body and event id.
|--------------------------------------------------------------------------
*/

Route::post('v1/integrations/store-manager/payment-events', [StoreManagerPaymentWebhookController::class, 'handle'])
    ->name('integrations.store-manager.payment-events');

/*
|--------------------------------------------------------------------------
| Admin POD Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum', 'branch.scope'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | POD (Admin)
            |--------------------------------------------------------------------------
            | Auto generated permissions:
            | pod.view
            | pod.collect
            | pod.deposit
            */

            Route::get('pod', [PodController::class, 'index'])
                ->name('pod.index');

            Route::post('pod/{pod}/collect', [PodController::class, 'collect'])
                ->name('pod.collect');

            Route::post('pod/deposit', [PodController::class, 'deposit'])
                ->name('pod.deposit');
        });
    });

/*
|--------------------------------------------------------------------------
| Merchant POD Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/merchant')
    ->name('merchant.')
    ->middleware(['auth:sanctum', 'role:merchant', 'branch.scope'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | POD (Merchant)
            |--------------------------------------------------------------------------
            | Permissions:
            | merchant.pod OR pod.view
            */

            Route::get('pod', [PodController::class, 'index'])
                ->name('pod.index');
        });
    });

/*
|--------------------------------------------------------------------------
| Staff POD Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/staff')
    ->name('staff.')
    ->middleware(['auth:sanctum', 'branch.scope'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | POD (Staff)
            |--------------------------------------------------------------------------
            | Permissions:
            | staff.pod OR pod.view
            */

            Route::get('pod', [PodController::class, 'index'])
                ->name('pod.index');

            Route::post('pod/deposit', [PodController::class, 'deposit'])
                ->name('pod.deposit');
        });
    });