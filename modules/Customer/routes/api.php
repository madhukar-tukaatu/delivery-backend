<?php

use Illuminate\Support\Facades\Route;
use Modules\Customer\Http\Controllers\CustomerController;

/*
|--------------------------------------------------------------------------
| Admin Customer Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum', 'branch.scope'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Customers (Admin)
            |--------------------------------------------------------------------------
            | Auto generated permissions:
            | customers.view
            | customers.create
            | customers.edit
            | customers.delete
            */

            Route::apiResource('customers', CustomerController::class)
                ->names([
                    'index' => 'customers.index',
                    'store' => 'customers.store',
                    'show' => 'customers.show',
                    'update' => 'customers.update',
                    'destroy' => 'customers.destroy',
                ]);
        });
    });

/*
|--------------------------------------------------------------------------
| Merchant Customer Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/merchant')
    ->name('merchant.')
    ->middleware(['auth:sanctum', 'role:merchant', 'branch.scope'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Customers (Merchant)
            |--------------------------------------------------------------------------
            | Permissions:
            | customers.view
            | customers.create
            | customers.edit
            | customers.delete
            */

            Route::apiResource('customers', CustomerController::class)
                ->names([
                    'index' => 'customers.index',
                    'store' => 'customers.store',
                    'show' => 'customers.show',
                    'update' => 'customers.update',
                    'destroy' => 'customers.destroy',
                ]);
        });
    });