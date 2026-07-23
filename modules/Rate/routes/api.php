<?php

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
        /*
         * Pricing simulator.
         */
        Route::post(
            'pricing/test',
            [AdminPricingTestController::class, 'test']
        )->name('pricing.test');

        /*
         * Service types.
         */
        Route::get(
            'service-types',
            [AdminSetupCrudController::class, 'serviceTypes']
        )->name('service-types.index');

        Route::post(
            'service-types',
            [AdminSetupCrudController::class, 'saveServiceType']
        )->name('service-types.store');

        /*
         * Branch pricing rules.
         */
        Route::get(
            'branch-pricing-rules',
            [AdminSetupCrudController::class, 'branchPricing']
        )->name('branch-pricing-rules.index');

        Route::post(
            'branch-pricing-rules',
            [AdminSetupCrudController::class, 'saveBranchPricing']
        )->name('branch-pricing-rules.store');

        /*
         * Inter-branch transfer counts.
         */
        Route::get(
            'inter-branch-transfer-counts',
            [AdminSetupCrudController::class, 'transferCounts']
        )->name('inter-branch-transfer-counts.index');

        Route::post(
            'inter-branch-transfer-counts',
            [AdminSetupCrudController::class, 'saveTransferCount']
        )->name('inter-branch-transfer-counts.store');

        /*
         * Transfer count rates.
         */
        Route::get(
            'transfer-count-rates',
            [AdminSetupCrudController::class, 'transferCountRates']
        )->name('transfer-count-rates.index');

        Route::post(
            'transfer-count-rates',
            [AdminSetupCrudController::class, 'saveTransferCountRate']
        )->name('transfer-count-rates.store');

        /*
         * Weight pricing rules.
         */
        Route::get(
            'weight-rate-rules',
            [AdminSetupCrudController::class, 'weightRates']
        )->name('weight-rate-rules.index');

        Route::post(
            'weight-rate-rules',
            [AdminSetupCrudController::class, 'saveWeightRate']
        )->name('weight-rate-rules.store');

        /*
         * Parcel handling rates.
         */
        Route::get(
            'parcel-handling-rates',
            [AdminSetupCrudController::class, 'handlingRates']
        )->name('parcel-handling-rates.index');

        Route::post(
            'parcel-handling-rates',
            [AdminSetupCrudController::class, 'saveHandlingRate']
        )->name('parcel-handling-rates.store');

        /*
         * POD / COD rate rules.
         */
        Route::get(
            'pod-rate-rules',
            [AdminSetupCrudController::class, 'codRates']
        )->name('pod-rate-rules.index');

        Route::post(
            'pod-rate-rules',
            [AdminSetupCrudController::class, 'saveCodRate']
        )->name('pod-rate-rules.store');
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
         * Calculate delivery charge only.
         * Does not save a quote or create a shipment.
         */
        Route::post(
            'pricing/check',
            [PublicPricingQuoteController::class, 'checkPrice']
        )->name('pricing.check');

        /*
         * Create a quote for one store or pickup location.
         */
        Route::post(
            'pricing/quotes',
            [PublicPricingQuoteController::class, 'storeSingle']
        )->name('pricing-quotes.store');

        /*
         * Retrieve one single-store quote.
         */
        Route::get(
            'pricing/quotes/{quoteNumber}',
            [PublicPricingQuoteController::class, 'showSingleQuote']
        )
            ->where('quoteNumber', '[A-Za-z0-9\-]+')
            ->name('pricing-quotes.show');

        /*
         * Create a combined quote for multiple stores.
         */
        Route::post(
            'pricing/checkout-quotes',
            [PublicPricingQuoteController::class, 'storeMultiStore']
        )->name('checkout-quotes.store');

        /*
         * Retrieve a multi-store checkout quote.
         */
        Route::get(
            'pricing/checkout-quotes/{quoteNumber}',
            [PublicPricingQuoteController::class, 'showCheckoutQuote']
        )
            ->where('quoteNumber', '[A-Za-z0-9\-]+')
            ->name('checkout-quotes.show');
    });
