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
    ->group(function (): void {

        /*
        |--------------------------------------------------------------------------
        | Create pickup
        |--------------------------------------------------------------------------
        */

        Route::post(
            'pickups',
            [GatewayPickupController::class, 'store']
        )->name('pickups.store');

        /*
        |--------------------------------------------------------------------------
        | Pickup status
        |--------------------------------------------------------------------------
        */

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
        'branch.scope',
    ])
    ->group(function (): void {

        /*
        |--------------------------------------------------------------------------
        | View pickup
        |--------------------------------------------------------------------------
        */

        Route::get(
            'pickups',
            [AdminPickupController::class, 'index']
        )
            ->middleware([
                'route.permission:pickups.view',
            ])
            ->name('pickups.index');

        /*
        |--------------------------------------------------------------------------
        | Pickup details
        |--------------------------------------------------------------------------
        */

        Route::get(
            'pickups/{pickup}',
            [AdminPickupController::class, 'show']
        )
            ->middleware([
                'route.permission:pickups.view',
            ])
            ->name('pickups.show');

        /*
        |--------------------------------------------------------------------------
        | Assignable staff
        |--------------------------------------------------------------------------
        |
        | This creates:
        |
        | pickups.assignable_staff
        |
        */

        Route::get(
            'pickups/{pickup}/assignable-staff',
            [AdminPickupController::class, 'assignableStaff']
        )
            ->middleware([
                'route.permission:pickups.assignable_staff',
            ])
            ->name('pickups.assignable-staff');

        /*
        |--------------------------------------------------------------------------
        | Assign pickup
        |--------------------------------------------------------------------------
        */

        Route::post(
            'pickups/{pickup}/assign',
            [AdminPickupController::class, 'assign']
        )
            ->middleware([
                'route.permission:pickups.assign',
            ])
            ->name('pickups.assign');

        /*
        |--------------------------------------------------------------------------
        | Transfer pickup
        |--------------------------------------------------------------------------
        */

        Route::post(
            'pickups/{pickup}/transfer',
            [AdminPickupController::class, 'transfer']
        )
            ->middleware([
                'route.permission:pickups.transfer',
            ])
            ->name('pickups.transfer');

        /*
        |--------------------------------------------------------------------------
        | Fail pickup
        |--------------------------------------------------------------------------
        */

        Route::post(
            'pickups/{pickup}/fail',
            [AdminPickupController::class, 'fail']
        )
            ->middleware([
                'route.permission:pickups.failed',
            ])
            ->name('pickups.fail');

        /*
        |--------------------------------------------------------------------------
        | Receive shipment
        |--------------------------------------------------------------------------
        */

        Route::post(
            'pickups/{pickup}/shipments/{shipment}/receive',
            [AdminPickupController::class, 'receiveShipment']
        )
            ->middleware([
                'route.permission:pickups.receive',
            ])
            ->name('pickups.shipments.receive');
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
    ->group(function (): void {

        /*
        |--------------------------------------------------------------------------
        | Merchant pickup list
        |--------------------------------------------------------------------------
        */

        Route::get(
            'pickups',
            [PickupController::class, 'index']
        )
            ->middleware([
                'route.permission:pickups.view',
            ])
            ->name('pickups.index');

        /*
        |--------------------------------------------------------------------------
        | Merchant pickup details
        |--------------------------------------------------------------------------
        */

        Route::get(
            'pickups/{pickup}',
            [PickupController::class, 'show']
        )
            ->middleware([
                'route.permission:pickups.view',
            ])
            ->name('pickups.show');

        /*
        |--------------------------------------------------------------------------
        | Add shipment to pickup
        |--------------------------------------------------------------------------
        */

        Route::post(
            'pickups/shipments',
            [PickupController::class, 'addShipment']
        )
            ->middleware([
                'route.permission:pickups.create',
            ])
            ->name('pickups.shipments.store');
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
    ->group(function (): void {

        /*
        |--------------------------------------------------------------------------
        | Pickup list
        |--------------------------------------------------------------------------
        */

        Route::get(
            'pickups',
            [PickupController::class, 'index']
        )
            ->middleware([
                'route.permission:pickups.view',
            ])
            ->name('pickups.index');

        /*
        |--------------------------------------------------------------------------
        | Pickup details
        |--------------------------------------------------------------------------
        */

        Route::get(
            'pickups/{pickup}',
            [PickupController::class, 'show']
        )
            ->middleware([
                'route.permission:pickups.view',
            ])
            ->name('pickups.show');

        /*
        |--------------------------------------------------------------------------
        | Start pickup
        |--------------------------------------------------------------------------
        */

        Route::post(
            'pickups/{pickup}/start',
            [PickupController::class, 'start']
        )
            ->middleware([
                'route.permission:pickups.accept',
            ])
            ->name('pickups.start');

        /*
        |--------------------------------------------------------------------------
        | Arrive at pickup
        |--------------------------------------------------------------------------
        */

        Route::post(
            'pickups/{pickup}/arrive',
            [PickupController::class, 'arrive']
        )
            ->middleware([
                'route.permission:pickups.status',
            ])
            ->name('pickups.arrive');

        /*
        |--------------------------------------------------------------------------
        | Collect shipment
        |--------------------------------------------------------------------------
        */

        Route::post(
            'pickups/{pickup}/shipments/{shipment}/collect',
            [PickupController::class, 'collectShipment']
        )
            ->middleware([
                'route.permission:pickups.picked_up',
            ])
            ->name('pickups.shipments.collect');

        /*
        |--------------------------------------------------------------------------
        | Complete pickup
        |--------------------------------------------------------------------------
        */

        Route::post(
            'pickups/{pickup}/complete',
            [PickupController::class, 'complete']
        )
            ->middleware([
                'route.permission:pickups.status',
            ])
            ->name('pickups.complete');

        /*
        |--------------------------------------------------------------------------
        | Receive shipment
        |--------------------------------------------------------------------------
        */

        Route::post(
            'pickups/{pickup}/shipments/{shipment}/receive',
            [PickupController::class, 'receiveShipment']
        )
            ->middleware([
                'route.permission:pickups.status',
            ])
            ->name('pickups.shipments.receive');

        /*
        |--------------------------------------------------------------------------
        | Fail pickup
        |--------------------------------------------------------------------------
        */

        Route::post(
            'pickups/{pickup}/fail',
            [PickupController::class, 'fail']
        )
            ->middleware([
                'route.permission:pickups.failed',
            ])
            ->name('pickups.fail');
    });