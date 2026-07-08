<?php

use Illuminate\Support\Facades\Route;
use Modules\Delivery\Http\Controllers\DeliveryController;

/*
|--------------------------------------------------------------------------
| Admin Delivery Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum', 'branch.scope'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Deliveries (Admin)
            |--------------------------------------------------------------------------
            | Auto generated permissions:
            | deliveries.view
            | deliveries.assign
            | deliveries.status
            */

            Route::get('deliveries', [DeliveryController::class, 'index'])
                ->name('deliveries.index');

            Route::post('shipments/{shipment}/assign-delivery', [DeliveryController::class, 'assign'])
                ->name('deliveries.assign');

            Route::post('deliveries/{assignment}/status', [DeliveryController::class, 'status'])
                ->name('deliveries.status');
        });
    });

/*
|--------------------------------------------------------------------------
| Staff Delivery Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/staff')
    ->name('staff.')
    ->middleware(['auth:sanctum', 'branch.scope'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Deliveries (Staff)
            |--------------------------------------------------------------------------
            | Permissions:
            | staff.deliveries OR deliveries.view
            */

            Route::get('deliveries', [DeliveryController::class, 'index'])
                ->name('deliveries.index');

            Route::post('deliveries/{assignment}/status', [DeliveryController::class, 'status'])
                ->name('deliveries.status');
        });
    });