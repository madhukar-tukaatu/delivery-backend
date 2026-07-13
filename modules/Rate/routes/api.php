<?php

use Illuminate\Support\Facades\Route;
use Modules\Rate\Http\Controllers\Api\AdminPricingTestController;
use Modules\Rate\Http\Controllers\Api\AdminSetupCrudController;
use Modules\Rate\Http\Controllers\Api\PublicPricingQuoteController;
use Modules\Rate\Http\Controllers\RateController;

/*
|--------------------------------------------------------------------------
| Admin Rate Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Rate Cards
            |--------------------------------------------------------------------------
            | rates.view | rates.manage
            */

            Route::get('rate-cards', [RateController::class, 'cards'])
                ->name('rate-cards.index');

            Route::post('rate-cards', [RateController::class, 'storeCard'])
                ->name('rate-cards.store');

            Route::put('rate-cards/{card}', [RateController::class, 'updateCard'])
                ->name('rate-cards.update');

            Route::delete('rate-cards/{card}', [RateController::class, 'deleteCard'])
                ->name('rate-cards.destroy');

            /*
            |--------------------------------------------------------------------------
            | Rate Rules
            |--------------------------------------------------------------------------
            | rates.view | rates.manage
            */

            Route::get('rate-rules', [RateController::class, 'rules'])
                ->name('rate-rules.index');

            Route::post('rate-rules', [RateController::class, 'storeRule'])
                ->name('rate-rules.store');

            Route::put('rate-rules/{rule}', [RateController::class, 'updateRule'])
                ->name('rate-rules.update');

            Route::delete('rate-rules/{rule}', [RateController::class, 'deleteRule'])
                ->name('rate-rules.destroy');

            /*
            |--------------------------------------------------------------------------
            | Rate Calculation
            |--------------------------------------------------------------------------
            | rates.calculate
            */

            Route::post('rates/calculate', [RateController::class, 'calculate'])
                ->name('rates.calculate');
        });
    });

/*
|--------------------------------------------------------------------------
| Merchant Rate Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/merchant')
    ->name('merchant.')
    ->middleware(['auth:sanctum', 'role:merchant'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Rate Calculation (Merchant)
            |--------------------------------------------------------------------------
            */

            Route::post('rates/calculate', [RateController::class, 'calculate'])
                ->name('rates.calculate');
        });
    });

/*
|--------------------------------------------------------------------------
| Gateway Rate Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/gateway')
    ->name('gateway.')
    ->middleware(['gateway.auth'])
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Rate Calculation (Gateway)
        |--------------------------------------------------------------------------
        | No permission middleware (external system)
        */

        Route::post('rates/calculate', [RateController::class, 'calculate'])
            ->name('rates.calculate');
    });



Route::prefix('v1/public')->group(function () {
    Route::post('/pricing/quote', [PublicPricingQuoteController::class, 'store']);
});
Route::prefix('v1/admin')->middleware(['auth:sanctum'])->group(function () {
    Route::post('/pricing/test', [AdminPricingTestController::class, 'test']);
    Route::get('/service-types', [AdminSetupCrudController::class, 'serviceTypes']);
    Route::post('/service-types', [AdminSetupCrudController::class, 'saveServiceType']);
    Route::get('/branch-pricing-rules', [AdminSetupCrudController::class, 'branchPricing']);
    Route::post('/branch-pricing-rules', [AdminSetupCrudController::class, 'saveBranchPricing']);
    Route::get('/branch-transfer-lanes', [AdminSetupCrudController::class, 'transferLanes']);
    Route::post('/branch-transfer-lanes', [AdminSetupCrudController::class, 'saveTransferLane']);
});
