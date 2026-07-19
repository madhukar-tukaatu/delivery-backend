<?php

use Illuminate\Support\Facades\Route;
use Modules\Report\Http\Controllers\ReportController;

/*
|--------------------------------------------------------------------------
| Admin Report Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Dashboard
            |--------------------------------------------------------------------------
            | reports.view
            */

            Route::get('dashboard', [ReportController::class, 'dashboard'])
                ->name('dashboard');

            /*
            |--------------------------------------------------------------------------
            | Reports
            |--------------------------------------------------------------------------
            | reports.view
            */

            Route::prefix('reports')->name('reports.')->group(function () {

                Route::get('shipments', [ReportController::class, 'shipments'])
                    ->name('shipments');

                Route::get('revenue', [ReportController::class, 'revenue'])
                    ->name('revenue');

                Route::get('pod', [ReportController::class, 'pod'])
                    ->name('pod');

                Route::get('merchants', [ReportController::class, 'merchants'])
                    ->name('merchants');

                Route::get('branches', [ReportController::class, 'branches'])
                    ->name('branches');

                Route::get('staff', [ReportController::class, 'staff'])
                    ->name('staff');
            });
        });
    });

/*
|--------------------------------------------------------------------------
| Merchant Dashboard Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/merchant')
    ->name('merchant.')
    ->middleware(['auth:sanctum', 'role:merchant'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Dashboard (Merchant)
            |--------------------------------------------------------------------------
            | merchant.dashboard
            */

            Route::get('dashboard', [ReportController::class, 'dashboard'])
                ->name('dashboard');
        });
    });

/*
|--------------------------------------------------------------------------
| Staff Dashboard Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/staff')
    ->name('staff.')
    ->middleware(['auth:sanctum'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Dashboard (Staff)
            |--------------------------------------------------------------------------
            | staff.dashboard
            */

            Route::get('dashboard', [ReportController::class, 'dashboard'])
                ->name('dashboard');
        });
    });