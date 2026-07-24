<?php

use Illuminate\Support\Facades\Route;
use Modules\Rate\Http\Controllers\Api\Admin\AdminPricingSettingsController;
use Modules\Rate\Http\Controllers\Api\Admin\AdminServiceTypeController;
use Modules\Rate\Http\Controllers\Api\Admin\AdminBranchRouteRateController;
use Modules\Rate\Http\Controllers\Api\Admin\AdminPricingQuoteController;
use Modules\Rate\Http\Controllers\Api\Admin\AdminPricingTestController;
use Modules\Rate\Http\Controllers\Api\PublicPricingQuoteController;

/*
|--------------------------------------------------------------------------
| Rate Module API Routes
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| Super Admin Pricing Management
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
        | Pricing Simulator
        |--------------------------------------------------------------------------
        */

        Route::post('pricing-test', [AdminPricingTestController::class, 'test'])->name('pricing-test');

        Route::post('pricing-simulator', [AdminPricingTestController::class, 'test'])->name('pricing-simulator');


        /*
        |--------------------------------------------------------------------------
        | Pricing Settings
        |--------------------------------------------------------------------------
        */

        Route::prefix('pricing-settings')
            ->name('pricing-settings.')
            ->controller(AdminPricingSettingsController::class)
            ->group(function (): void {

                Route::get('/', 'index')->name('index');

                Route::post('/', 'store')->name('store');

                Route::get('/{pricingSetting}', 'show')->name('show');

                Route::put('/{pricingSetting}', 'update')->name('update');

                Route::post('/{pricingSetting}/activate', 'activate')->name('activate');

                Route::delete('/{pricingSetting}', 'destroy')->name('destroy');
            });


        /*
        |--------------------------------------------------------------------------
        | Service Types
        |--------------------------------------------------------------------------
        */

        Route::prefix('service-types')
            ->name('service-types.')
            ->controller(AdminServiceTypeController::class)
            ->group(function (): void {

                Route::get('/', 'index')->name('index');

                Route::post('/', 'store')->name('store');

                Route::get('/{serviceType}', 'show')->name('show');

                Route::put('/{serviceType}', 'update')->name('update');

                Route::patch('/{serviceType}/status', 'toggle')->name('status');

                Route::delete('/{serviceType}', 'destroy')->name('destroy');
            });


        /*
        |--------------------------------------------------------------------------
        | Branch Route Pricing
        |--------------------------------------------------------------------------
        |
        | These rates are customer-facing branch-to-branch prices.
        | Only main branches should be returned by the branches endpoint.
        |
        */

        Route::prefix('branch-route-rates')
            ->name('branch-route-rates.')
            ->controller(AdminBranchRouteRateController::class)
            ->group(function (): void {

                /*
                 * Fixed routes must remain above /{branchRouteRate}.
                 */
                Route::get('/branches', 'branches')->name('branches');

                Route::get('/matrix', 'matrix')->name('matrix');

                Route::get('/', 'index')->name('index');

                Route::post('/', 'store')->name('store');

                Route::get('/{branchRouteRate}', 'show')->name('show');

                Route::put('/{branchRouteRate}', 'update')->name('update');

                Route::patch('/{branchRouteRate}/status', 'toggle')->name('status');

                Route::delete('/{branchRouteRate}', 'destroy')->name('destroy');
            });


        /*
        |--------------------------------------------------------------------------
        | Stored Pricing Quotes
        |--------------------------------------------------------------------------
        */

        Route::prefix('pricing-quotes')
            ->name('pricing-quotes.')
            ->controller(AdminPricingQuoteController::class)
            ->group(function (): void {

                Route::get('/', 'index')->name('index');

                Route::get('/{pricingQuote}', 'show')->name('show');

                Route::delete('/{pricingQuote}', 'destroy')->name('destroy');
            });
    });


/*
|--------------------------------------------------------------------------
| Public Merchant Pricing API
|--------------------------------------------------------------------------
|
| Replace "merchant.api-key" below only when your existing middleware
| alias uses a different name.
|
*/

Route::prefix('v1/public-merchant/pricing')
    ->name('public-merchant.pricing.')
    ->middleware([
        'merchant.api-key',
    ])
    ->controller(PublicPricingQuoteController::class)
    ->group(function (): void {

        /*
         * Calculate delivery price without storing a quote.
         */
        Route::post('/check-price', 'checkPrice')->name('check-price');

        /*
         * Create and retrieve one single-store quote.
         */
        Route::post('/quotes', 'storeSingle')->name('quotes.store');

        Route::get('/quotes/{quoteNumber}', 'showSingleQuote')->name('quotes.show');

        /*
         * Create and retrieve a multi-store checkout quote.
         */
        Route::post('/checkout-quotes', 'storeMultiStore')->name('checkout-quotes.store');

        Route::get('/checkout-quotes/{quoteNumber}', 'showCheckoutQuote')->name('checkout-quotes.show');
    });