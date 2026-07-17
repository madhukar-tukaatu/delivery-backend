<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Rate\Http\Controllers\Api\AdminPricingTestController;
use Modules\Rate\Http\Controllers\Api\AdminSetupCrudController;
use Modules\Rate\Http\Controllers\Api\PublicPricingQuoteController;

/*
|--------------------------------------------------------------------------
| Admin Pricing Configuration
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware([
        'auth:sanctum',
        'route.permission',
    ])
    ->group(function (): void {
        Route::post(
            'pricing/test',
            [AdminPricingTestController::class, 'test']
        )->name('pricing.test');

        Route::get(
            'service-types',
            [AdminSetupCrudController::class, 'serviceTypes']
        )->name('service-types.index');

        Route::post(
            'service-types',
            [AdminSetupCrudController::class, 'saveServiceType']
        )->name('service-types.store');

        Route::get(
            'branch-pricing-rules',
            [AdminSetupCrudController::class, 'branchPricing']
        )->name('branch-pricing-rules.index');

        Route::post(
            'branch-pricing-rules',
            [AdminSetupCrudController::class, 'saveBranchPricing']
        )->name('branch-pricing-rules.store');

        Route::get(
            'inter-branch-transfer-counts',
            [AdminSetupCrudController::class, 'transferCounts']
        )->name('inter-branch-transfer-counts.index');

        Route::post(
            'inter-branch-transfer-counts',
            [AdminSetupCrudController::class, 'saveTransferCount']
        )->name('inter-branch-transfer-counts.store');

        Route::get(
            'transfer-count-rates',
            [AdminSetupCrudController::class, 'transferCountRates']
        )->name('transfer-count-rates.index');

        Route::post(
            'transfer-count-rates',
            [AdminSetupCrudController::class, 'saveTransferCountRate']
        )->name('transfer-count-rates.store');

        Route::get(
            'weight-rate-rules',
            [AdminSetupCrudController::class, 'weightRates']
        )->name('weight-rate-rules.index');

        Route::post(
            'weight-rate-rules',
            [AdminSetupCrudController::class, 'saveWeightRate']
        )->name('weight-rate-rules.store');

        Route::get(
            'parcel-handling-rates',
            [AdminSetupCrudController::class, 'handlingRates']
        )->name('parcel-handling-rates.index');

        Route::post(
            'parcel-handling-rates',
            [AdminSetupCrudController::class, 'saveHandlingRate']
        )->name('parcel-handling-rates.store');

        Route::get(
            'cod-rate-rules',
            [AdminSetupCrudController::class, 'codRates']
        )->name('cod-rate-rules.index');

        Route::post(
            'cod-rate-rules',
            [AdminSetupCrudController::class, 'saveCodRate']
        )->name('cod-rate-rules.store');
    });

/*
|--------------------------------------------------------------------------
| Public Merchant Pricing
|--------------------------------------------------------------------------
*/

Route::prefix('v1/public-merchant')
    ->name('public-merchant.')
    ->middleware([
        'merchant.api-key',
        'throttle:public-merchant',
    ])
    ->group(function (): void {
        /*
         * One pickup/store only.
         */
        Route::post(
            'pricing/quotes',
            [PublicPricingQuoteController::class, 'storeSingle']
        )->name('pricing-quotes.single');

        /*
         * Multiple stores/pickup locations.
         */
        Route::post(
            'pricing/checkout-quotes',
            [PublicPricingQuoteController::class, 'storeMultiStore']
        )->name('pricing-quotes.multi-store');

        Route::get(
            'pricing/checkout-quotes/{quoteNumber}',
            [PublicPricingQuoteController::class, 'showCheckoutQuote']
        )->name('pricing-quotes.show');
    });