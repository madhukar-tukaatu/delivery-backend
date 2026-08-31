<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Pickup\Http\Controllers\GatewayPickupController;
use Modules\Pickup\Http\Controllers\PickupController;

/*
|--------------------------------------------------------------------------
| Pickup API Routes
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| External Store Manager / Gateway
|--------------------------------------------------------------------------
|
| Authentication:
|
| X-Tukaatu-Key
| X-Tukaatu-Secret
|
| merchant.api-key populates:
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
    ->group(function (): void {

        /*
        |--------------------------------------------------------------------------
        | Request pickup
        |--------------------------------------------------------------------------
        |
        | Store Manager sends:
        |
        | pickup_location_id
        | preferred_pickup_at
        | remarks
        |
        | It does NOT send shipment IDs.
        |
        | Tukaatu automatically finds:
        |
        | merchant
        | pickup location
        | awaiting_pickup shipments
        |
        | and attaches eligible shipments to the pickup.
        |
        */

        Route::post(
            'pickups',
            [GatewayPickupController::class, 'store']
        )->name('pickups.store');


        /*
        |--------------------------------------------------------------------------
        | Get pickup
        |--------------------------------------------------------------------------
        */

        Route::get(
            'pickups/{requestNumber}',
            [GatewayPickupController::class, 'show']
        )->name('pickups.show');
    });


/*
|--------------------------------------------------------------------------
| Admin / Branch Management
|--------------------------------------------------------------------------
|
| Used by:
|
| - Super Admin
| - Main Admin
| - Branch Manager
| - Sub Branch Manager
|
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
            | Pickup list
            |--------------------------------------------------------------------------
            |
            | Branch managers only receive pickups belonging to their
            | branch/sub-branch through PickupController@index.
            |
            */

            Route::get(
                'pickups',
                [PickupController::class, 'index']
            )->name('pickups.index');


            /*
            |--------------------------------------------------------------------------
            | Pickup details
            |--------------------------------------------------------------------------
            */

            Route::get(
                'pickups/{pickup}',
                [PickupController::class, 'show']
            )->name('pickups.show');


            /*
            |--------------------------------------------------------------------------
            | Assign rider
            |--------------------------------------------------------------------------
            |
            | Branch Manager selects a rider/staff member.
            |
            | POST:
            |
            | /admin/pickups/{pickup}/assign
            |
            | Body:
            |
            | {
            |     "staff_id": 123
            | }
            |
            */

            Route::post(
                'pickups/{pickup}/assign',
                [PickupController::class, 'assign']
            )->name('pickups.assign');


            /*
            |--------------------------------------------------------------------------
            | Transfer pickup
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            |
            | Transfer is to another branch/sub-branch.
            |
            | It is NOT assigned directly to another rider.
            |
            | Body:
            |
            | {
            |     "target_branch_id": 10,
            |     "target_sub_branch_id": 25,
            |     "reason": "No riders available"
            | }
            |
            */

            Route::post(
                'pickups/{pickup}/transfer',
                [PickupController::class, 'transfer']
            )->name('pickups.transfer');


            /*
            |--------------------------------------------------------------------------
            | Cancel / fail pickup
            |--------------------------------------------------------------------------
            */

            Route::post(
                'pickups/{pickup}/fail',
                [PickupController::class, 'fail']
            )->name('pickups.fail');


            /*
            |--------------------------------------------------------------------------
            | Receive shipment at origin branch
            |--------------------------------------------------------------------------
            */

            Route::post(
                'pickups/{pickup}/shipments/{shipment}/receive',
                [PickupController::class, 'receiveShipment']
            )->name('pickups.shipments.receive');
        });
    });


/*
|--------------------------------------------------------------------------
| Internal Merchant Portal
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

            /*
            |--------------------------------------------------------------------------
            | Pickup list
            |--------------------------------------------------------------------------
            */

            Route::get(
                'pickups',
                [PickupController::class, 'index']
            )->name('pickups.index');


            /*
            |--------------------------------------------------------------------------
            | Pickup details
            |--------------------------------------------------------------------------
            */

            Route::get(
                'pickups/{pickup}',
                [PickupController::class, 'show']
            )->name('pickups.show');


            /*
            |--------------------------------------------------------------------------
            | Manual attachment
            |--------------------------------------------------------------------------
            |
            | Internal fallback only.
            |
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
|
| Riders can only operate on pickups assigned to them.
|
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

            /*
            |--------------------------------------------------------------------------
            | My pickups
            |--------------------------------------------------------------------------
            */

            Route::get(
                'pickups',
                [PickupController::class, 'index']
            )->name('pickups.index');


            /*
            |--------------------------------------------------------------------------
            | Pickup details
            |--------------------------------------------------------------------------
            */

            Route::get(
                'pickups/{pickup}',
                [PickupController::class, 'show']
            )->name('pickups.show');


            /*
            |--------------------------------------------------------------------------
            | Start pickup
            |--------------------------------------------------------------------------
            */

            Route::post(
                'pickups/{pickup}/start',
                [PickupController::class, 'start']
            )->name('pickups.start');


            /*
            |--------------------------------------------------------------------------
            | Arrive at merchant
            |--------------------------------------------------------------------------
            */

            Route::post(
                'pickups/{pickup}/arrive',
                [PickupController::class, 'arrive']
            )->name('pickups.arrive');


            /*
            |--------------------------------------------------------------------------
            | Collect shipment
            |--------------------------------------------------------------------------
            */

            Route::post(
                'pickups/{pickup}/shipments/{shipment}/collect',
                [PickupController::class, 'collectShipment']
            )->name('pickups.shipments.collect');


            /*
            |--------------------------------------------------------------------------
            | Complete pickup
            |--------------------------------------------------------------------------
            */

            Route::post(
                'pickups/{pickup}/complete',
                [PickupController::class, 'complete']
            )->name('pickups.complete');


            /*
            |--------------------------------------------------------------------------
            | Receive shipment at origin
            |--------------------------------------------------------------------------
            */

            Route::post(
                'pickups/{pickup}/shipments/{shipment}/receive',
                [PickupController::class, 'receiveShipment']
            )->name('pickups.shipments.receive');


            /*
            |--------------------------------------------------------------------------
            | Fail pickup
            |--------------------------------------------------------------------------
            */

            Route::post(
                'pickups/{pickup}/fail',
                [PickupController::class, 'fail']
            )->name('pickups.fail');
        });
    });