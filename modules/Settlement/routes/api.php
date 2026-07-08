<?php

use Illuminate\Support\Facades\Route;
use Modules\Settlement\Http\Controllers\SettlementController;

/*
|--------------------------------------------------------------------------
| Admin Settlement Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Settlements (Admin)
            |--------------------------------------------------------------------------
            | Auto generated permissions:
            | settlements.view
            | settlements.create
            | settlements.pay
            */

            Route::get('settlements', [SettlementController::class, 'index'])
                ->name('settlements.index');

            Route::post('settlements', [SettlementController::class, 'store'])
                ->name('settlements.store');

            Route::post('settlements/{settlement}/mark-paid', [SettlementController::class, 'markPaid'])
                ->name('settlements.mark-paid');
        });
    });

/*
|--------------------------------------------------------------------------
| Merchant Settlement Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/merchant')
    ->name('merchant.')
    ->middleware(['auth:sanctum', 'role:merchant'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Settlements (Merchant)
            |--------------------------------------------------------------------------
            | Permissions:
            | merchant.settlements OR settlements.view
            */

            Route::get('settlements', [SettlementController::class, 'index'])
                ->name('settlements.index');
        });
    });