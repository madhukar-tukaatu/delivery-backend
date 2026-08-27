<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Pickup\Http\Controllers\GatewayPickupController;
use Modules\Pickup\Http\Controllers\PickupController;

/*
|--------------------------------------------------------------------------
| External Store Gateway
|--------------------------------------------------------------------------
|
| Authentication:
|
| X-Tukaatu-Key
| X-Tukaatu-Secret
|
| Your existing gateway authentication middleware must populate:
|
| request()->attributes->get('merchant_id')
|
|--------------------------------------------------------------------------
*/

Route::prefix('v1/gateway')
    ->name('gateway.')
    ->middleware([
         'merchant.api-key',
    ])
    ->group(function () {

        Route::post(
            'pickups',
            [GatewayPickupController::class, 'store']
        )->name('pickups.store');

        Route::get(
            'pickups/{requestNumber}',
            [GatewayPickupController::class, 'show']
        )->name('pickups.show');
    });


/*
|--------------------------------------------------------------------------
| Admin
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware([
        'auth:sanctum',
        'branch.scope',
    ])
    ->group(function () {

        Route::middleware([
            'route.permission',
        ])->group(function () {

            Route::get(
                'pickups',
                [PickupController::class, 'index']
            )->name('pickups.index');

            Route::get(
                'pickups/{pickup}',
                [PickupController::class, 'show']
            )->name('pickups.show');

            Route::post(
                'pickups/{pickup}/assign',
                [PickupController::class, 'assign']
            )->name('pickups.assign');

            Route::post(
                'pickups/{pickup}/fail',
                [PickupController::class, 'fail']
            )->name('pickups.fail');

            Route::post(
                'pickups/{pickup}/shipments/{shipment}/receive',
                [PickupController::class, 'receiveShipment']
            )->name('pickups.shipments.receive');
        });
    });


/*
|--------------------------------------------------------------------------
| Merchant Portal
|--------------------------------------------------------------------------
*/

Route::prefix('v1/merchant')
    ->name('merchant.')
    ->middleware([
        'auth:sanctum',
        'role:merchant',
        'branch.scope',
    ])
    ->group(function () {

        Route::middleware([
            'route.permission',
        ])->group(function () {

            Route::get(
                'pickups',
                [PickupController::class, 'index']
            )->name('pickups.index');

            Route::get(
                'pickups/{pickup}',
                [PickupController::class, 'show']
            )->name('pickups.show');

            /*
             * Internal merchant portal only.
             *
             * External Store Manager should use:
             *
             * POST /api/v1/gateway/pickups
             */

            Route::post(
                'pickups/shipments',
                [PickupController::class, 'addShipment']
            )->name('pickups.shipments.store');
        });
    });


/*
|--------------------------------------------------------------------------
| Staff / Rider
|--------------------------------------------------------------------------
*/

Route::prefix('v1/staff')
    ->name('staff.')
    ->middleware([
        'auth:sanctum',
        'branch.scope',
    ])
    ->group(function () {

        Route::middleware([
            'route.permission',
        ])->group(function () {

            Route::get(
                'pickups',
                [PickupController::class, 'index']
            )->name('pickups.index');

            Route::get(
                'pickups/{pickup}',
                [PickupController::class, 'show']
            )->name('pickups.show');

            Route::post(
                'pickups/{pickup}/start',
                [PickupController::class, 'start']
            )->name('pickups.start');

            Route::post(
                'pickups/{pickup}/arrive',
                [PickupController::class, 'arrive']
            )->name('pickups.arrive');

            Route::post(
                'pickups/{pickup}/shipments/{shipment}/collect',
                [PickupController::class, 'collectShipment']
            )->name('pickups.shipments.collect');

            Route::post(
                'pickups/{pickup}/shipments/{shipment}/receive',
                [PickupController::class, 'receiveShipment']
            )->name('pickups.shipments.receive');

            Route::post(
                'pickups/{pickup}/fail',
                [PickupController::class, 'fail']
            )->name('pickups.fail');
        });
    });