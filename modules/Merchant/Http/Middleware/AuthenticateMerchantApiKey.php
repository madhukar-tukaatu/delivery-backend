<?php

declare(strict_types=1);

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
    ->middleware([
        'auth:sanctum',
        'route.permission',
    ])
    ->group(function (): void {
        /*
        |--------------------------------------------------------------------------
        | Existing Rate Cards
        |--------------------------------------------------------------------------
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
        | Existing Rate Rules
        |--------------------------------------------------------------------------
        */

        Route::get('rate-rules', [RateController::class, 'rules'])
            ->name('rate-rules.index');

        Route::post('rate-rules', [RateController::class, 'storeRule'])
            ->name('rate-rules.store');

        Route::put('rate-rules/{rule}', [RateController::class, 'updateRule'])
            ->name('rate-rules.update');

        Route::delete('rate-rules/{rule}', [RateController::class, 'deleteRule'])
            ->name('rate-rules.destroy');

        Route::post('rates/calculate', [RateController::class, 'calculate'])
            ->name('rates.calculate');

        /*
        |--------------------------------------------------------------------------
        | New Pricing Engine
        |--------------------------------------------------------------------------
        */

        Route::post('pricing/test', [AdminPricingTestController::class, 'test'])
            ->name('pricing.test');

        Route::get('service-types', [AdminSetupCrudController::class, 'serviceTypes'])
            ->name('service-types.index');

        Route::post('service-types', [AdminSetupCrudController::class, 'saveServiceType'])
            ->name('service-types.store');

        Route::get('branch-pricing-rules', [AdminSetupCrudController::class, 'branchPricing'])
            ->name('branch-pricing-rules.index');

        Route::post('branch-pricing-rules', [AdminSetupCrudController::class, 'saveBranchPricing'])
            ->name('branch-pricing-rules.store');

        Route::get('inter-branch-transfer-counts', [AdminSetupCrudController::class, 'transferCounts'])
            ->name('inter-branch-transfer-counts.index');

        Route::post('inter-branch-transfer-counts', [AdminSetupCrudController::class, 'saveTransferCount'])
            ->name('inter-branch-transfer-counts.store');

        Route::get('transfer-count-rates', [AdminSetupCrudController::class, 'transferCountRates'])
            ->name('transfer-count-rates.index');

        Route::post('transfer-count-rates', [AdminSetupCrudController::class, 'saveTransferCountRate'])
            ->name('transfer-count-rates.store');

        Route::get('weight-rate-rules', [AdminSetupCrudController::class, 'weightRates'])
            ->name('weight-rate-rules.index');

        Route::post('weight-rate-rules', [AdminSetupCrudController::class, 'saveWeightRate'])
            ->name('weight-rate-rules.store');

        Route::get('parcel-handling-rates', [AdminSetupCrudController::class, 'handlingRates'])
            ->name('parcel-handling-rates.index');

        Route::post('parcel-handling-rates', [AdminSetupCrudController::class, 'saveHandlingRate'])
            ->name('parcel-handling-rates.store');

        Route::get('pod-rate-rules', [AdminSetupCrudController::class, 'podRates'])
            ->name('pod-rate-rules.index');

        Route::post('pod-rate-rules', [AdminSetupCrudController::class, 'savePodRate'])
            ->name('pod-rate-rules.store');
    });

/*
|--------------------------------------------------------------------------
| Merchant Rate Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/merchant')
    ->name('merchant.')
    ->middleware([
        'auth:sanctum',
        'role:merchant',
        'route.permission',
    ])
    ->group(function (): void {
        Route::post('rates/calculate', [RateController::class, 'calculate'])
            ->name('rates.calculate');
    });

/*
|--------------------------------------------------------------------------
| Gateway Rate Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/gateway')
    ->name('gateway.')
    ->middleware('gateway.auth')
    ->group(function (): void {
        Route::post('rates/calculate', [RateController::class, 'calculate'])
            ->name('rates.calculate');
    });

/*
|--------------------------------------------------------------------------
| Public Merchant Quote Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/public-merchant')
    ->name('public-merchant.')
    ->middleware([
        'merchant.api-key',
        'throttle:public-merchant',
    ])
    ->group(function (): void {
        Route::post('pricing/quotes', [PublicPricingQuoteController::class, 'store'])
            ->name('pricing-quotes.store');

        Route::get('pricing/quotes/{quoteNumber}', [PublicPricingQuoteController::class, 'show'])
            ->name('pricing-quotes.show');
    });