<?php

use Illuminate\Support\Facades\Route;
use Modules\Rate\Http\Controllers\Api\Admin\AdminBranchRouteRateController;
use Modules\Rate\Http\Controllers\Api\Admin\AdminPricingQuoteController;
use Modules\Rate\Http\Controllers\Api\Admin\AdminPricingSettingsController;
use Modules\Rate\Http\Controllers\Api\Admin\AdminPricingTestController;
use Modules\Rate\Http\Controllers\Api\Admin\AdminServiceTypeController;
use Modules\Rate\Http\Controllers\Api\PublicPricingQuoteController;

/*
|--------------------------------------------------------------------------
| Rate Admin API
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum', 'route.permission'])
    ->group(function (): void {
        /*
        |--------------------------------------------------------------------------
        | Pricing Settings
        |--------------------------------------------------------------------------
        */

        Route::get('pricing-settings', [AdminPricingSettingsController::class, 'index'])->name('pricing-settings.index');
        Route::post('pricing-settings', [AdminPricingSettingsController::class, 'store'])->name('pricing-settings.store');
        Route::get('pricing-settings/{pricingSetting}', [AdminPricingSettingsController::class, 'show'])->name('pricing-settings.show');
        Route::put('pricing-settings/{pricingSetting}', [AdminPricingSettingsController::class, 'update'])->name('pricing-settings.update');
        Route::post('pricing-settings/{pricingSetting}/activate', [AdminPricingSettingsController::class, 'activate'])->name('pricing-settings.activate');
        Route::delete('pricing-settings/{pricingSetting}', [AdminPricingSettingsController::class, 'destroy'])->name('pricing-settings.destroy');

        /*
        |--------------------------------------------------------------------------
        | Service Types
        |--------------------------------------------------------------------------
        */

        Route::get('service-types', [AdminServiceTypeController::class, 'index'])->name('service-types.index');
        Route::post('service-types', [AdminServiceTypeController::class, 'store'])->name('service-types.store');
        Route::get('service-types/{serviceType}', [AdminServiceTypeController::class, 'show'])->name('service-types.show');
        Route::put('service-types/{serviceType}', [AdminServiceTypeController::class, 'update'])->name('service-types.update');
        Route::patch('service-types/{serviceType}/status', [AdminServiceTypeController::class, 'toggle'])->name('service-types.status');
        Route::delete('service-types/{serviceType}', [AdminServiceTypeController::class, 'destroy'])->name('service-types.destroy');

        /*
        |--------------------------------------------------------------------------
        | Branch Route Rates
        |--------------------------------------------------------------------------
        |
        | Fixed routes must be above the dynamic {branchRouteRate} route.
        |
        */

        Route::get('branch-route-rates/branches', [AdminBranchRouteRateController::class, 'branches'])->name('branch-route-rates.branches');
        Route::get('branch-route-rates/matrix', [AdminBranchRouteRateController::class, 'matrix'])->name('branch-route-rates.matrix');
        Route::get('branch-route-rates', [AdminBranchRouteRateController::class, 'index'])->name('branch-route-rates.index');
        Route::post('branch-route-rates', [AdminBranchRouteRateController::class, 'store'])->name('branch-route-rates.store');
        Route::get('branch-route-rates/{branchRouteRate}', [AdminBranchRouteRateController::class, 'show'])->name('branch-route-rates.show');
        Route::put('branch-route-rates/{branchRouteRate}', [AdminBranchRouteRateController::class, 'update'])->name('branch-route-rates.update');
        Route::patch('branch-route-rates/{branchRouteRate}/status', [AdminBranchRouteRateController::class, 'toggle'])->name('branch-route-rates.status');
        Route::delete('branch-route-rates/{branchRouteRate}', [AdminBranchRouteRateController::class, 'destroy'])->name('branch-route-rates.destroy');

        /*
        |--------------------------------------------------------------------------
        | Pricing Simulator
        |--------------------------------------------------------------------------
        */

        Route::post('pricing-simulator', [AdminPricingTestController::class, 'calculate'])->name('pricing-simulator.calculate');
        Route::post('pricing-test', [AdminPricingTestController::class, 'calculate'])->name('pricing-test.calculate');

        /*
        |--------------------------------------------------------------------------
        | Pricing Quotes
        |--------------------------------------------------------------------------
        */

        Route::get('pricing-quotes', [AdminPricingQuoteController::class, 'index'])->name('pricing-quotes.index');
        Route::get('pricing-quotes/{pricingQuote}', [AdminPricingQuoteController::class, 'show'])->name('pricing-quotes.show');
        Route::delete('pricing-quotes/{pricingQuote}', [AdminPricingQuoteController::class, 'destroy'])->name('pricing-quotes.destroy');
    });

/*
|--------------------------------------------------------------------------
| Public Merchant Pricing API
|--------------------------------------------------------------------------
*/

Route::prefix('v1/public-merchant/pricing')
    ->name('public-merchant.pricing.')
    ->middleware(['merchant.api-key'])
    ->group(function (): void {
        Route::post('check', [PublicPricingQuoteController::class, 'checkPrice'])->name('check-price');
        Route::post('quotes', [PublicPricingQuoteController::class, 'storeSingle'])->name('quotes.store');
        Route::get('quotes/{quoteNumber}', [PublicPricingQuoteController::class, 'showSingleQuote'])->name('quotes.show');
    });