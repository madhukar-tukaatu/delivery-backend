<?php

declare (strict_types = 1);

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

        /*
        |--------------------------------------------------------------------------
        | REQUEST PICKUP
        |--------------------------------------------------------------------------
        |
        | The store calls this when it wants Tukaatu to perform
        | a physical pickup.
        |
        | Example:
        |
        | store_reference = PR-001
        |
        */

        Route::post(
            'pickups',
            [
                GatewayPickupController::class,
                'store',
            ]
        )->name('pickups.store');

        /*
        |--------------------------------------------------------------------------
        | GET PICKUP
        |--------------------------------------------------------------------------
        */

        Route::get(
            'pickups/{requestNumber}',
            [
                GatewayPickupController::class,
                'show',
            ]
        )->name('pickups.show');
    });

/*
|--------------------------------------------------------------------------
| ADMIN / BRANCH PICKUP MANAGEMENT
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware([
        'auth:sanctum',
        'branch.scope',
    ])
    ->group(function (): void {

        Route::get(
            'pickups',
            [
                AdminPickupController::class,
                'index',
            ]
        )
            ->middleware([
                'route.permission:pickups.view',
            ])
            ->name('pickups.index');

        Route::get(
            'pickups/{pickup}',
            [
                AdminPickupController::class,
                'show',
            ]
        )
            ->middleware([
                'route.permission:pickups.view',
            ])
            ->name('pickups.show');

        Route::get(
            'pickups/{pickup}/assignable-staff',
            [
                AdminPickupController::class,
                'assignableStaff',
            ]
        )
            ->middleware([
                'route.permission:pickups.assignable_staff',
            ])
            ->name('pickups.assignable-staff');

        Route::post(
            'pickups/{pickup}/assign',
            [
                AdminPickupController::class,
                'assign',
            ]
        )
            ->middleware([
                'route.permission:pickups.assign',
            ])
            ->name('pickups.assign');

        Route::post(
            'pickups/{pickup}/transfer',
            [
                AdminPickupController::class,
                'transfer',
            ]
        )
            ->middleware([
                'route.permission:pickups.transfer',
            ])
            ->name('pickups.transfer');

        Route::post(
            'pickups/{pickup}/fail',
            [
                AdminPickupController::class,
                'fail',
            ]
        )
            ->middleware([
                'route.permission:pickups.failed',
            ])
            ->name('pickups.fail');

        Route::post(
            'pickups/{pickup}/shipments/{shipment}/receive',
            [
                AdminPickupController::class,
                'receiveShipment',
            ]
        )
            ->middleware([
                'route.permission:pickups.receive',
            ])
            ->name('pickups.shipments.receive');

        Route::post(
            'pickups/{pickup}/resend-callback',
            [
                AdminPickupController::class,
                'resendCallback',
            ]
        )
            ->middleware([
                'route.permission:pickups.assign',
            ])
            ->name('pickups.resend-callback');
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

        Route::get(
            'pickups',
            [
                PickupController::class,
                'index',
            ]
        )
            ->middleware([
                'route.permission:pickups.view',
            ])
            ->name('pickups.index');

        Route::get(
            'pickups/{pickup}',
            [
                PickupController::class,
                'show',
            ]
        )
            ->middleware([
                'route.permission:pickups.view',
            ])
            ->name('pickups.show');

        Route::post(
            'pickups/shipments',
            [
                PickupController::class,
                'addShipment',
            ]
        )
            ->middleware([
                'route.permission:pickups.create',
            ])
            ->name('pickups.shipments.store');
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

        /*
        |--------------------------------------------------------------------------
        | PICKUP LIST
        |--------------------------------------------------------------------------
        */

        Route::get(
            'pickups',
            [
                PickupController::class,
                'index',
            ]
        )
            ->middleware([
                'route.permission:pickups.view',
            ])
            ->name('pickups.index');

        /*
        |--------------------------------------------------------------------------
        | PICKUP DETAILS
        |--------------------------------------------------------------------------
        */

        Route::get(
            'pickups/{pickup}',
            [
                PickupController::class,
                'show',
            ]
        )
            ->middleware([
                'route.permission:pickups.view',
            ])
            ->name('pickups.show');

        /*
        |--------------------------------------------------------------------------
        | START
        |--------------------------------------------------------------------------
        */

        Route::post(
            'pickups/{pickup}/start',
            [
                PickupController::class,
                'start',
            ]
        )
            ->middleware([
                'route.permission:pickups.accept',
            ])
            ->name('pickups.start');

        /*
        |--------------------------------------------------------------------------
        | ARRIVE
        |--------------------------------------------------------------------------
        */

        Route::post(
            'pickups/{pickup}/arrive',
            [
                PickupController::class,
                'arrive',
            ]
        )
            ->middleware([
                'route.permission:pickups.status',
            ])
            ->name('pickups.arrive');

        /*
        |--------------------------------------------------------------------------
        | COLLECT SHIPMENT
        |--------------------------------------------------------------------------
        */

        Route::post(
            'pickups/{pickup}/shipments/{shipment}/collect',
            [
                PickupController::class,
                'collectShipment',
            ]
        )
            ->middleware([
                'route.permission:pickups.picked_up',
            ])
            ->name('pickups.shipments.collect');

        /*
        |--------------------------------------------------------------------------
        | COMPLETE PICKUP
        |--------------------------------------------------------------------------
        */

        Route::post(
            'pickups/{pickup}/complete',
            [
                PickupController::class,
                'complete',
            ]
        )
            ->middleware([
                'route.permission:pickups.status',
            ])
            ->name('pickups.complete');

        /*
        |--------------------------------------------------------------------------
        | RECEIVE SHIPMENT
        |--------------------------------------------------------------------------
        */

        Route::post(
            'pickups/{pickup}/shipments/{shipment}/receive',
            [
                PickupController::class,
                'receiveShipment',
            ]
        )
            ->middleware([
                'route.permission:pickups.status',
            ])
            ->name('pickups.shipments.receive');

        /*
        |--------------------------------------------------------------------------
        | FAIL PICKUP
        |--------------------------------------------------------------------------
        */

        Route::post(
            'pickups/{pickup}/fail',
            [
                PickupController::class,
                'fail',
            ]
        )
            ->middleware([
                'route.permission:pickups.failed',
            ])
            ->name('pickups.fail');
    });
