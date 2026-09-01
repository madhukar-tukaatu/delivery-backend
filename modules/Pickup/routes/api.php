<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Pickup\Http\Controllers\AdminPickupController;
use Modules\Pickup\Http\Controllers\GatewayPickupController;
use Modules\Pickup\Http\Controllers\PickupController;


/*
|--------------------------------------------------------------------------
| Gateway
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
| Admin Pickup Management
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware([
        'auth:sanctum',
    ])
    ->group(function () {

        Route::middleware([
            'route.permission',
        ])->group(function () {

            Route::get(
                'pickups',
                [AdminPickupController::class, 'index']
            )->name('pickups.index');

            Route::get(
                'pickups/{pickup}',
                [AdminPickupController::class, 'show']
            )
                ->whereNumber('pickup')
                ->name('pickups.show');

            Route::get(
                'pickups/{pickup}/assignable-staff',
                [AdminPickupController::class, 'assignableStaff']
            )
                ->whereNumber('pickup')
                ->name('pickups.assignable-staff');

            Route::post(
                'pickups/{pickup}/assign',
                [AdminPickupController::class, 'assign']
            )
                ->whereNumber('pickup')
                ->name('pickups.assign');

            Route::post(
                'pickups/{pickup}/transfer',
                [AdminPickupController::class, 'transfer']
            )
                ->whereNumber('pickup')
                ->name('pickups.transfer');

            Route::post(
                'pickups/{pickup}/fail',
                [AdminPickupController::class, 'fail']
            )
                ->whereNumber('pickup')
                ->name('pickups.fail');

            Route::post(
                'pickups/{pickup}/shipments/{shipment}/receive',
                [AdminPickupController::class, 'receiveShipment']
            )
                ->whereNumber('pickup')
                ->whereNumber('shipment')
                ->name('pickups.shipments.receive');
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
            )
                ->whereNumber('pickup')
                ->name('pickups.show');

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
            )
                ->whereNumber('pickup')
                ->name('pickups.show');

            Route::post(
                'pickups/{pickup}/start',
                [PickupController::class, 'start']
            )
                ->whereNumber('pickup')
                ->name('pickups.start');

            Route::post(
                'pickups/{pickup}/arrive',
                [PickupController::class, 'arrive']
            )
                ->whereNumber('pickup')
                ->name('pickups.arrive');

            Route::post(
                'pickups/{pickup}/shipments/{shipment}/collect',
                [PickupController::class, 'collectShipment']
            )
                ->whereNumber('pickup')
                ->whereNumber('shipment')
                ->name('pickups.shipments.collect');

            Route::post(
                'pickups/{pickup}/complete',
                [PickupController::class, 'complete']
            )
                ->whereNumber('pickup')
                ->name('pickups.complete');

            Route::post(
                'pickups/{pickup}/shipments/{shipment}/receive',
                [PickupController::class, 'receiveShipment']
            )
                ->whereNumber('pickup')
                ->whereNumber('shipment')
                ->name('pickups.shipments.receive');

            Route::post(
                'pickups/{pickup}/fail',
                [PickupController::class, 'fail']
            )
                ->whereNumber('pickup')
                ->name('pickups.fail');
        });
    });