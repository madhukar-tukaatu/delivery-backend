<?php

use Illuminate\Support\Facades\Route;
use Modules\POD\Http\Controllers\GatewayPodPaymentController;
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

Route::post('v1/express/callback', [StoreManagerPaymentWebhookController::class, 'handle'])
    ->name('express.callback');

Route::post('v1/integrations/pod-payment/callback', [StoreManagerPaymentWebhookController::class, 'handle'])
    ->name('integrations.pod-payment.callback');

Route::post('v1/integrations/store-manager/payment-events', [StoreManagerPaymentWebhookController::class, 'handle'])
    ->name('integrations.store-manager.payment-events');

/*
|--------------------------------------------------------------------------
| GATEWAY - POD QR (Phase 6 HamroPay)
|--------------------------------------------------------------------------
| Auth: merchant.api-key (X-Tukaatu-Key / X-Tukaatu-Secret)
| Store-initiated gateway POD QR (HamroPay on Express) — separate from rider doorstep.
| Rider doorstep POD uses POST /staff/deliveries/{id}/pod-payment -> Tukaatu.
| Inbound callback: POST /api/v1/express/callback. pod_payment.* stays on the delivery POD handler.
|--------------------------------------------------------------------------
*/

Route::prefix('v1/gateway')
    ->name('gateway.')
    ->middleware(['merchant.api-key'])
    ->group(function () {
        Route::post('payments/pod-qr', [GatewayPodPaymentController::class, 'createQr'])
            ->name('payments.pod-qr');

        Route::post('payments/pod-qr/verify', [GatewayPodPaymentController::class, 'verify'])
            ->name('payments.pod-qr.verify');
    });

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
