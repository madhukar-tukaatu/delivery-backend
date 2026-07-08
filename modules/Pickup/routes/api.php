<?php

use Illuminate\Support\Facades\Route;
use Modules\Pickup\Http\Controllers\PickupController;

/*
|--------------------------------------------------------------------------
| Admin Pickup Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum', 'branch.scope'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Pickups (Admin)
            |--------------------------------------------------------------------------
            | Auto generated permissions:
            | pickups.view
            | pickups.create
            | pickups.assign
            | pickups.status
            */

            Route::get('pickups', [PickupController::class, 'index'])
                ->name('pickups.index');

            Route::post('pickups', [PickupController::class, 'store'])
                ->name('pickups.store');

            Route::post('pickups/{pickup}/assign', [PickupController::class, 'assign'])
                ->name('pickups.assign');

            Route::post('pickups/{pickup}/status', [PickupController::class, 'status'])
                ->name('pickups.status');
        });
    });

/*
|--------------------------------------------------------------------------
| Merchant Pickup Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/merchant')
    ->name('merchant.')
    ->middleware(['auth:sanctum', 'role:merchant', 'branch.scope'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Pickups (Merchant)
            |--------------------------------------------------------------------------
            | Permissions:
            | merchant.pickups OR pickups.view
            */

            Route::get('pickups', [PickupController::class, 'index'])
                ->name('pickups.index');

            Route::post('pickups', [PickupController::class, 'store'])
                ->name('pickups.store');
        });
    });

/*
|--------------------------------------------------------------------------
| Staff Pickup Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/staff')
    ->name('staff.')
    ->middleware(['auth:sanctum', 'branch.scope'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Pickups (Staff)
            |--------------------------------------------------------------------------
            | Permissions:
            | staff.pickups OR pickups.view
            */

            Route::get('pickups', [PickupController::class, 'index'])
                ->name('pickups.index');

            Route::post('pickups/{pickup}/status', [PickupController::class, 'status'])
                ->name('pickups.status');
        });
    });