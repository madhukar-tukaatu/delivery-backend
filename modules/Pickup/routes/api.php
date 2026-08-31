<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Pickup\Http\Controllers\AdminPickupController;
use Modules\Pickup\Http\Controllers\GatewayPickupController;
use Modules\Pickup\Http\Controllers\PickupController;

/*
|--------------------------------------------------------------------------
| Pickup API Routes
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| EXTERNAL STORE MANAGER / GATEWAY
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
        | REQUEST PICKUP
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
        | GatewayPickupService automatically:
        |
        | merchant
        | pickup location
        | awaiting_pickup shipments
        | PickupRequest
        | pickup_request_shipments
        |
        |--------------------------------------------------------------------------
        */

        Route::post(
            'pickups',
            [GatewayPickupController::class, 'store']
        )->name('pickups.store');


        /*
        |--------------------------------------------------------------------------
        | GET PICKUP
        |--------------------------------------------------------------------------
        */

        Route::get(
            'pickups/{requestNumber}',
            [GatewayPickupController::class, 'show']
        )->name('pickups.show');
    });


/*
|--------------------------------------------------------------------------
| ADMIN / BRANCH MANAGEMENT
|--------------------------------------------------------------------------
|
| Used by:
|
| - Super Admin
| - Main Admin
| - Branch Manager
| - Sub Branch Manager
|
| IMPORTANT:
|
| Pickup administration belongs to the Pickup module.
|
| Controller:
|
| Modules\Pickup\Http\Controllers\AdminPickupController
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
            | PICKUPS
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
                'pickups/{pickup}',
                [AdminPickupController::class, 'show']
            )->name('pickups.show');


            /*
            |--------------------------------------------------------------------------
            | ASSIGNABLE STAFF
            |--------------------------------------------------------------------------
            */

            Route::get(
                'pickups/{pickup}/assignable-staff',
                [AdminPickupController::class, 'assignableStaff']
            )->name('pickups.assignable-staff');


            /*
            |--------------------------------------------------------------------------
            | ASSIGN PICKUP
            |--------------------------------------------------------------------------
            |
            | POST /api/v1/admin/pickups/{pickup}/assign
            |
            | {
            |     "staff_id": 123
            | }
            |
            */

            Route::post(
                'pickups/{pickup}/assign',
                [AdminPickupController::class, 'assign']
            )->name('pickups.assign');


            /*
            |--------------------------------------------------------------------------
            | FAIL PICKUP
            |--------------------------------------------------------------------------
            |
            | POST /api/v1/admin/pickups/{pickup}/fail
            |
            | {
            |     "reason": "No merchant available"
            | }
            |
            */

            Route::post(
                'pickups/{pickup}/fail',
                [AdminPickupController::class, 'fail']
            )->name('pickups.fail');


            /*
            |--------------------------------------------------------------------------
            | STAFF
            |--------------------------------------------------------------------------
            */

            /*
            | Keep your existing AdminStaffController routes here.
            |
            | These should remain owned by the Shipment/Admin area only
            | if that is where your current staff controller lives.
            */
        });
    });


/*
|--------------------------------------------------------------------------
| INTERNAL MERCHANT PORTAL
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
            | PICKUP LIST
            |--------------------------------------------------------------------------
            */

            Route::get(
                'pickups',
                [PickupController::class, 'index']
            )->name('pickups.index');


            /*
            |--------------------------------------------------------------------------
            | PICKUP DETAILS
            |--------------------------------------------------------------------------
            */

            Route::get(
                'pickups/{pickup}',
                [PickupController::class, 'show']
            )->name('pickups.show');


            /*
            |--------------------------------------------------------------------------
            | MANUAL SHIPMENT ATTACHMENT
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
| STAFF / RIDER
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
            | MY PICKUPS
            |--------------------------------------------------------------------------
            */

            Route::get(
                'pickups',
                [PickupController::class, 'index']
            )->name('pickups.index');


            /*
            |--------------------------------------------------------------------------
            | PICKUP DETAILS
            |--------------------------------------------------------------------------
            */

            Route::get(
                'pickups/{pickup}',
                [PickupController::class, 'show']
            )->name('pickups.show');


            /*
            |--------------------------------------------------------------------------
            | START PICKUP
            |--------------------------------------------------------------------------
            */

            Route::post(
                'pickups/{pickup}/start',
                [PickupController::class, 'start']
            )->name('pickups.start');


            /*
            |--------------------------------------------------------------------------
            | ARRIVE AT MERCHANT
            |--------------------------------------------------------------------------
            */

            Route::post(
                'pickups/{pickup}/arrive',
                [PickupController::class, 'arrive']
            )->name('pickups.arrive');


            /*
            |--------------------------------------------------------------------------
            | COLLECT SHIPMENT
            |--------------------------------------------------------------------------
            */

            Route::post(
                'pickups/{pickup}/shipments/{shipment}/collect',
                [PickupController::class, 'collectShipment']
            )->name('pickups.shipments.collect');


            /*
            |--------------------------------------------------------------------------
            | COMPLETE PICKUP
            |--------------------------------------------------------------------------
            */

            Route::post(
                'pickups/{pickup}/complete',
                [PickupController::class, 'complete']
            )->name('pickups.complete');


            /*
            |--------------------------------------------------------------------------
            | RECEIVE SHIPMENT AT ORIGIN
            |--------------------------------------------------------------------------
            */

            Route::post(
                'pickups/{pickup}/shipments/{shipment}/receive',
                [PickupController::class, 'receiveShipment']
            )->name('pickups.shipments.receive');


            /*
            |--------------------------------------------------------------------------
            | FAIL PICKUP
            |--------------------------------------------------------------------------
            */

            Route::post(
                'pickups/{pickup}/fail',
                [PickupController::class, 'fail']
            )->name('pickups.fail');
        });
    });