<?php

use Illuminate\Support\Facades\Route;
use Modules\Rate\Http\Controllers\Api\Admin\AdminBranchRouteRateController;
use Modules\Rate\Http\Controllers\Api\Admin\AdminBranchTransferLaneController;
use Modules\Rate\Http\Controllers\Api\Admin\AdminBranchTransferRouteController;
use Modules\Rate\Http\Controllers\Api\Admin\AdminPricingDefaultsController;
use Modules\Rate\Http\Controllers\Api\Admin\AdminPricingQuoteController;
use Modules\Rate\Http\Controllers\Api\Admin\AdminPricingReturnRuleController;
use Modules\Rate\Http\Controllers\Api\Admin\AdminPricingSettingsController;
use Modules\Rate\Http\Controllers\Api\Admin\AdminPricingTestController;
use Modules\Rate\Http\Controllers\Api\Admin\AdminServiceTypeController;
use Modules\Rate\Http\Controllers\Api\Admin\AdminTransferRoutePricingProfileController;
use Modules\Rate\Http\Controllers\Api\Admin\AdminTransferRoutePricingSettingsController;
use Modules\Rate\Http\Controllers\Api\MarketplacePricingQuoteController;
use Modules\Rate\Http\Controllers\Api\PublicPricingEstimateController;
use Modules\Rate\Http\Controllers\Api\PublicPricingQuoteController;

/*
|--------------------------------------------------------------------------
| Rate Admin API
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')
    ->prefix('v1/admin/rate')
    ->group(function (): void {
        /*
        |--------------------------------------------------------------------------
        | Transfer lanes
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/branch-transfer-lanes',
            [
                AdminBranchTransferLaneController::class,
                'index',
            ]
        )
            ->middleware(
                'permission:pricing.transfer_lanes.view'
            )
            ->name(
                'admin.pricing.transfer-lanes.index'
            )
            ->adminMenu(
                label: 'Transfer Lanes',
                frontendRoute: '/admin/branch-transfer-lanes',
                icon: 'transfer',
                sortOrder: 95
            );

        Route::post(
            '/branch-transfer-lanes',
            [
                AdminBranchTransferLaneController::class,
                'store',
            ]
        )
            ->middleware(
                'permission:pricing.transfer_lanes.create'
            )
            ->name(
                'admin.pricing.transfer-lanes.store'
            );

        Route::put(
            '/branch-transfer-lanes/{lane}',
            [
                AdminBranchTransferLaneController::class,
                'update',
            ]
        )
            ->middleware(
                'permission:pricing.transfer_lanes.update'
            )
            ->name(
                'admin.pricing.transfer-lanes.update'
            );

        Route::patch(
            '/branch-transfer-lanes/{lane}/status',
            [
                AdminBranchTransferLaneController::class,
                'updateStatus',
            ]
        )
            ->middleware(
                'permission:pricing.transfer_lanes.status'
            )
            ->name(
                'admin.pricing.transfer-lanes.status'
            );

        Route::delete(
            '/branch-transfer-lanes/{lane}',
            [
                AdminBranchTransferLaneController::class,
                'destroy',
            ]
        )
            ->middleware(
                'permission:pricing.transfer_lanes.delete'
            )
            ->name(
                'admin.pricing.transfer-lanes.destroy'
            );

        /*
        |--------------------------------------------------------------------------
        | Complete transfer routes
        |--------------------------------------------------------------------------
        */

        Route::get(
            '/branch-transfer-routes',
            [
                AdminBranchTransferRouteController::class,
                'index',
            ]
        )
            ->middleware(
                'permission:pricing.transfer_routes.view'
            )
            ->name(
                'admin.pricing.transfer-routes.index'
            )
            ->adminMenu(
                label: 'Transfer Routes',
                frontendRoute: '/admin/branch-transfer-routes',
                icon: 'routes',
                sortOrder: 96
            );

        Route::post(
            '/branch-transfer-routes/preview',
            [
                AdminBranchTransferRouteController::class,
                'preview',
            ]
        )
            ->middleware(
                'permission:pricing.transfer_routes.view'
            )
            ->name(
                'admin.pricing.transfer-routes.preview'
            );

        Route::post(
            '/branch-transfer-routes',
            [
                AdminBranchTransferRouteController::class,
                'store',
            ]
        )
            ->middleware(
                'permission:pricing.transfer_routes.create'
            )
            ->name(
                'admin.pricing.transfer-routes.store'
            );

        Route::put(
            '/branch-transfer-routes/{transferRoute}',
            [
                AdminBranchTransferRouteController::class,
                'update',
            ]
        )
            ->middleware(
                'permission:pricing.transfer_routes.update'
            )
            ->name(
                'admin.pricing.transfer-routes.update'
            );

        Route::patch(
            '/branch-transfer-routes/{transferRoute}/status',
            [
                AdminBranchTransferRouteController::class,
                'updateStatus',
            ]
        )
            ->middleware(
                'permission:pricing.transfer_routes.status'
            )
            ->name(
                'admin.pricing.transfer-routes.status'
            );

        Route::delete(
            '/branch-transfer-routes/{transferRoute}',
            [
                AdminBranchTransferRouteController::class,
                'destroy',
            ]
        )
            ->middleware(
                'permission:pricing.transfer_routes.delete'
            )
            ->name(
                'admin.pricing.transfer-routes.destroy'
            );

        Route::get(
            '/branch-transfer-routes/{branchTransferRoute}/pricing-settings',
            [
                AdminTransferRoutePricingSettingsController::class,
                'index',
            ]
        )->whereNumber('branchTransferRoute');

        Route::post(
            '/branch-transfer-routes/{branchTransferRoute}/pricing-settings',
            [
                AdminTransferRoutePricingSettingsController::class,
                'store',
            ]
        )->whereNumber('branchTransferRoute');

        Route::get(
            '/branch-transfer-routes/{branchTransferRoute}/pricing-profile',
            [
                AdminTransferRoutePricingProfileController::class,
                'show',
            ]
        )->whereNumber('branchTransferRoute');

        Route::put(
            '/branch-transfer-routes/{branchTransferRoute}/pricing-profile',
            [
                AdminTransferRoutePricingProfileController::class,
                'update',
            ]
        )->whereNumber('branchTransferRoute');

        /*
 * Keep use-global before routes containing {pricingSetting}.
 */
        Route::post(
            '/branch-transfer-routes/{branchTransferRoute}/pricing-settings/use-global',
            [
                AdminTransferRoutePricingSettingsController::class,
                'useGlobal',
            ]
        )->whereNumber('branchTransferRoute');

        Route::post(
            '/branch-transfer-routes/{branchTransferRoute}/pricing-settings/{pricingSetting}/activate',
            [
                AdminTransferRoutePricingSettingsController::class,
                'activate',
            ]
        )
            ->whereNumber('branchTransferRoute')
            ->whereNumber('pricingSetting');

        Route::delete(
            '/branch-transfer-routes/{branchTransferRoute}/pricing-settings/{pricingSetting}',
            [
                AdminTransferRoutePricingSettingsController::class,
                'destroy',
            ]
        )
            ->whereNumber('branchTransferRoute')
            ->whereNumber('pricingSetting');
    });

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum', 'route.permission'])
    ->group(function (): void {
        Route::get('pricing-settings/defaults', [AdminPricingSettingsController::class, 'defaults',]);
        Route::get('pricing-settings', [AdminPricingSettingsController::class, 'index'])->name('pricing-settings.index');
        Route::post('pricing-settings', [AdminPricingSettingsController::class, 'store'])->name('pricing-settings.store');
        Route::get('pricing-settings/{pricingSetting}', [AdminPricingSettingsController::class, 'show'])->name('pricing-settings.show');
        Route::put('pricing-settings/{pricingSetting}', [AdminPricingSettingsController::class, 'update'])->name('pricing-settings.update');
        Route::post('pricing-settings/{pricingSetting}/activate', [AdminPricingSettingsController::class, 'activate'])->name('pricing-settings.activate');
        Route::delete('pricing-settings/{pricingSetting}', [AdminPricingSettingsController::class, 'destroy'])->name('pricing-settings.destroy');

        Route::get('service-types', [AdminServiceTypeController::class, 'index'])->name('service-types.index');
        Route::post('service-types', [AdminServiceTypeController::class, 'store'])->name('service-types.store');
        Route::get('service-types/{serviceType}', [AdminServiceTypeController::class, 'show'])->name('service-types.show');
        Route::put('service-types/{serviceType}', [AdminServiceTypeController::class, 'update'])->name('service-types.update');
        Route::patch('service-types/{serviceType}/status', [AdminServiceTypeController::class, 'toggle'])->name('service-types.status');
        Route::delete('service-types/{serviceType}', [AdminServiceTypeController::class, 'destroy'])->name('service-types.destroy');

        Route::get('branch-route-rates/branches', [AdminBranchRouteRateController::class, 'branches'])->name('branch-route-rates.branches');
        Route::get('branch-route-rates/matrix', [AdminBranchRouteRateController::class, 'matrix'])->name('branch-route-rates.matrix');
        Route::get('branch-route-rates', [AdminBranchRouteRateController::class, 'index'])->name('branch-route-rates.index');
        Route::post('branch-route-rates', [AdminBranchRouteRateController::class, 'store'])->name('branch-route-rates.store');
        Route::get('branch-route-rates/{branchRouteRate}', [AdminBranchRouteRateController::class, 'show'])->name('branch-route-rates.show');
        Route::put('branch-route-rates/{branchRouteRate}', [AdminBranchRouteRateController::class, 'update'])->name('branch-route-rates.update');
        Route::patch('branch-route-rates/{branchRouteRate}/status', [AdminBranchRouteRateController::class, 'toggle'])->name('branch-route-rates.status');
        Route::delete('branch-route-rates/{branchRouteRate}', [AdminBranchRouteRateController::class, 'destroy'])->name('branch-route-rates.destroy');

        Route::post('pricing-simulator', [AdminPricingTestController::class, 'calculate'])->name('pricing-simulator.calculate');
        Route::post('pricing-test', [AdminPricingTestController::class, 'calculate'])->name('pricing-test.calculate');

        Route::get('pricing-quotes', [AdminPricingQuoteController::class, 'index'])->name('pricing-quotes.index');
        Route::get('pricing-quotes/{pricingQuote}', [AdminPricingQuoteController::class, 'show'])->name('pricing-quotes.show');
        Route::delete('pricing-quotes/{pricingQuote}', [AdminPricingQuoteController::class, 'destroy'])->name('pricing-quotes.destroy');


        Route::get(
            'pricing-defaults/preview',
            [AdminPricingDefaultsController::class, 'preview']
        )->name('pricing-defaults.preview');

        Route::post(
            'pricing-defaults/import',
            [AdminPricingDefaultsController::class, 'import']
        )->name('pricing-defaults.import');

        Route::get(
            'pricing-return-rules',
            [AdminPricingReturnRuleController::class, 'index']
        )->name('pricing-return-rules.index');

        Route::put(
            'pricing-return-rules/{pricingReturnRule}',
            [AdminPricingReturnRuleController::class, 'update']
        )->name('pricing-return-rules.update');

        Route::get(
            'branch-transfer-lanes/branches',
            [AdminBranchTransferLaneController::class, 'branches']
        )->name('branch-transfer-lanes.branches');

        Route::get(
            'branch-transfer-lanes',
            [AdminBranchTransferLaneController::class, 'index']
        )->name('branch-transfer-lanes.index');

        Route::post(
            'branch-transfer-lanes',
            [AdminBranchTransferLaneController::class, 'store']
        )->name('branch-transfer-lanes.store');

        Route::get(
            'branch-transfer-lanes/{branchTransferLane}',
            [AdminBranchTransferLaneController::class, 'show']
        )->name('branch-transfer-lanes.show');

        Route::put(
            'branch-transfer-lanes/{branchTransferLane}',
            [AdminBranchTransferLaneController::class, 'update']
        )->name('branch-transfer-lanes.update');

        Route::patch(
            'branch-transfer-lanes/{branchTransferLane}/status',
            [AdminBranchTransferLaneController::class, 'toggle']
        )->name('branch-transfer-lanes.status');

        Route::delete(
            'branch-transfer-lanes/{branchTransferLane}',
            [AdminBranchTransferLaneController::class, 'destroy']
        )->name('branch-transfer-lanes.destroy');
    });

/*
|--------------------------------------------------------------------------
| Single-store merchant pricing API
|--------------------------------------------------------------------------
*/

Route::prefix('v1/public-merchant/pricing')
    ->name('public-merchant.pricing.')
    ->middleware(['merchant.api-key'])
    ->group(function (): void {
        Route::post('check', [PublicPricingQuoteController::class, 'checkPrice'])->name('check');
        Route::post('quotes', [PublicPricingQuoteController::class, 'storeSingle'])->name('quotes.store');
        Route::get('quotes/{quoteNumber}', [PublicPricingQuoteController::class, 'showSingleQuote'])->name('quotes.show');
    });

/*
|--------------------------------------------------------------------------
| Marketplace multi-store pricing API
|--------------------------------------------------------------------------
|
| Every request must include:
| X-Tukaatu-Marketplace-Key
| X-Tukaatu-Timestamp
| X-Tukaatu-Request-Id
| X-Tukaatu-Signature
|
*/

Route::prefix('v1/marketplace/pricing')
    ->name('marketplace.pricing.')
    ->middleware([
        'marketplace.api-key',
        'throttle:marketplace-pricing',
    ])
    ->group(function (): void {
        Route::post('check', [MarketplacePricingQuoteController::class, 'check'])->name('check');
        Route::post('checkout-quotes', [MarketplacePricingQuoteController::class, 'store'])->name('checkout-quotes.store');
        Route::get('checkout-quotes/{quoteNumber}', [MarketplacePricingQuoteController::class, 'show'])->name('checkout-quotes.show');
    });


Route::prefix('v1/public/pricing')
    ->name('public.pricing.')
    ->middleware('throttle:30,1')
    ->group(function (): void {
        Route::post(
            'estimate',
            PublicPricingEstimateController::class
        )->name('estimate');
    });
