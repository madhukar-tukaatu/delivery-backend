<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Pickup\Http\Controllers\AdminPickupController;
use Modules\Pickup\Http\Controllers\GatewayPickupController;
use Modules\Pickup\Http\Controllers\PickupController;

/*
|--------------------------------------------------------------------------
| EXTERNAL STORE MANAGER / GATEWAY
|--------------------------------------------------------------------------
*/

Route::prefix('v1/gateway')
    ->name('gateway.')
    ->middleware([
        'merchant.api-key',
    ])
    ->group(function (): void {

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
| ADMIN / BRANCH MANAGEMENT
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware([
        'auth:sanctum',
        'branch.scope',
    ])
    ->group(function (): void {

        Route::middleware([
            'route.permission',
        ])->group(function (): void {

            /*
            |--------------------------------------------------------------------------
            | PICKUP LIST
            |--------------------------------------------------------------------------
            */

            Route::get(
                'pickups',
                [AdminPickupController::class, 'index']
            )->name('pickups.index');


            /*
            |--------------------------------------------------------------------------
            | PICKUP DETAILS
            |--------------------------------------------------------------------------
            */

            Route::get(
                'pickups/{pickup:request_number}',
                [AdminPickupController::class, 'show']
            )->name('pickups.show');


            /*
            |--------------------------------------------------------------------------
            | ASSIGNABLE RIDERS
            |--------------------------------------------------------------------------
            */

            Route::get(
                'pickups/{pickup:request_number}/assignable-staff',
                [AdminPickupController::class, 'assignableStaff']
            )->name('pickups.assignable-staff');


            /*
            |--------------------------------------------------------------------------
            | ASSIGN / REASSIGN
            |--------------------------------------------------------------------------
            */

            Route::post(
                'pickups/{pickup:request_number}/assign',
                [AdminPickupController::class, 'assign']
            )->name('pickups.assign');


            /*
            |--------------------------------------------------------------------------
            | TRANSFER
            |--------------------------------------------------------------------------
            */

            Route::post(
                'pickups/{pickup:request_number}/transfer',
                [AdminPickupController::class, 'transfer']
            )->name('pickups.transfer');


            /*
            |--------------------------------------------------------------------------
            | FAIL
            |--------------------------------------------------------------------------
            */

            Route::post(
                'pickups/{pickup:request_number}/fail',
                [AdminPickupController::class, 'fail']
            )->name('pickups.fail');
        });
    });


/*
|--------------------------------------------------------------------------
| MERCHANT PORTAL
|--------------------------------------------------------------------------
*/

Route::prefix('v1/merchant')
    ->name('merchant.')
    ->middleware([
        'auth:sanctum',
        'role:merchant',
        'branch.scope',
    ])
    ->group(function (): void {

        Route::middleware([
            'route.permission',
        ])->group(function (): void {

            Route::get(
                'pickups',
                [PickupController::class, 'index']
            )->name('pickups.index');

            Route::get(
                'pickups/{pickup}',
                [PickupController::class, 'show']
            )->name('pickups.show');

            Route::post(
                'pickups/shipments',
                [PickupController::class, 'addShipment']
            )->name('pickups.shipments.store');
        });
    });


/*
|--------------------------------------------------------------------------
| STAFF / RIDER
|--------------------------------------------------------------------------
*/

Route::prefix('v1/staff')
    ->name('staff.')
    ->middleware([
        'auth:sanctum',
        'branch.scope',
    ])
    ->group(function (): void {

        Route::middleware([
            'route.permission',
        ])->group(function (): void {

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
                'pickups/{pickup}/complete',
                [PickupController::class, 'complete']
            )->name('pickups.complete');

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