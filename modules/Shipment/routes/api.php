<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

use Modules\Shipment\Http\Controllers\Api\AdminNotificationController;
use Modules\Shipment\Http\Controllers\Api\AdminPickupController;
use Modules\Shipment\Http\Controllers\Api\AdminShipmentTaskController;
use Modules\Shipment\Http\Controllers\Api\AdminStaffController;
use Modules\Shipment\Http\Controllers\Api\MerchantShipmentController;

use Modules\Shipment\Http\Controllers\GatewayShipmentController;
use Modules\Shipment\Http\Controllers\ShipmentController;

use Modules\Shipment\Http\Controllers\StaffDeliveryLifecycleController;
use Modules\Shipment\Http\Controllers\StaffPickupLifecycleController;

/*
|--------------------------------------------------------------------------
| Shipment API Routes
|--------------------------------------------------------------------------
|
| Shipment module routes.
|
| Main areas:
|
| /v1/admin/*
| /v1/staff/*
| /v1/merchant/*
| /v1/gateway/*
|
|--------------------------------------------------------------------------
*/


/*
|--------------------------------------------------------------------------
| STAFF
|--------------------------------------------------------------------------
|
| Used by:
|
| - Pickup staff
| - Riders
| - Delivery staff
|
|--------------------------------------------------------------------------
*/

Route::prefix('v1/staff')
    ->name('staff.')
    ->middleware([
        'auth:sanctum',
    ])
    ->group(function (): void {

        Route::middleware([
            'route.permission',
        ])->group(function (): void {

            /*
            |--------------------------------------------------------------------------
            | PICKUPS
            |--------------------------------------------------------------------------
            |
            | Controller:
            | StaffPickupLifecycleController
            |
            */

            Route::get(
                'pickups',
                [StaffPickupLifecycleController::class, 'index']
            )->name('pickups.index');

            Route::post(
                'pickups/{pickup}/accept',
                [StaffPickupLifecycleController::class, 'accept']
            )->name('pickups.accept');

            Route::post(
                'pickups/{pickup}/picked-up',
                [StaffPickupLifecycleController::class, 'pickedUp']
            )->name('pickups.picked-up');


            /*
            |--------------------------------------------------------------------------
            | DELIVERIES
            |--------------------------------------------------------------------------
            |
            | Controller:
            | StaffDeliveryLifecycleController
            |
            */

            Route::get(
                'deliveries',
                [StaffDeliveryLifecycleController::class, 'index']
            )->name('deliveries.index');

            Route::post(
                'deliveries/{delivery}/accept',
                [StaffDeliveryLifecycleController::class, 'accept']
            )->name('deliveries.accept');

            Route::post(
                'deliveries/{delivery}/out-for-delivery',
                [StaffDeliveryLifecycleController::class, 'outForDelivery']
            )->name('deliveries.out-for-delivery');

            Route::post(
                'deliveries/{delivery}/delivered',
                [StaffDeliveryLifecycleController::class, 'delivered']
            )->name('deliveries.delivered');

            Route::post(
                'deliveries/{delivery}/failed',
                [StaffDeliveryLifecycleController::class, 'failed']
            )->name('deliveries.failed');
        });
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
            | STAFF
            |--------------------------------------------------------------------------
            */

            Route::get(
                'staff',
                [AdminStaffController::class, 'index']
            )->name('staff.index');

            Route::post(
                'staff',
                [AdminStaffController::class, 'store']
            )->name('staff.store');

            Route::get(
                'staff/{staff}',
                [AdminStaffController::class, 'show']
            )->name('staff.show');

            Route::put(
                'staff/{staff}',
                [AdminStaffController::class, 'update']
            )->name('staff.update');

            Route::delete(
                'staff/{staff}',
                [AdminStaffController::class, 'destroy']
            )->name('staff.destroy');

            Route::post(
                'staff/{staff}/toggle',
                [AdminStaffController::class, 'toggle']
            )->name('staff.toggle');


            /*
            |--------------------------------------------------------------------------
            | PICKUPS
            |--------------------------------------------------------------------------
            */

            Route::get(
                'pickups',
                [AdminPickupController::class, 'index']
            )->name('pickups.index');

            Route::get(
                'pickups/{pickup}',
                [AdminPickupController::class, 'show']
            )->name('pickups.show');

            Route::get(
                'pickups/{pickup}/assignable-staff',
                [AdminPickupController::class, 'assignableStaff']
            )->name('pickups.assignable-staff');

            Route::post(
                'pickups/{pickup}/assign',
                [AdminPickupController::class, 'assign']
            )->name('pickups.assign');

            Route::post(
                'pickups/{pickup}/fail',
                [AdminPickupController::class, 'fail']
            )->name('pickups.fail');


            /*
            |--------------------------------------------------------------------------
            | SHIPMENTS
            |--------------------------------------------------------------------------
            */

            Route::get(
                'shipments',
                [ShipmentController::class, 'index']
            )->name('shipments.index');

            Route::post(
                'shipments',
                [ShipmentController::class, 'store']
            )->name('shipments.store');

            Route::get(
                'shipments/{shipment}',
                [ShipmentController::class, 'show']
            )->name('shipments.show');

            Route::put(
                'shipments/{shipment}',
                [ShipmentController::class, 'update']
            )->name('shipments.update');

            Route::post(
                'shipments/{shipment}/status',
                [ShipmentController::class, 'status']
            )->name('shipments.status');

            Route::post(
                'shipments/{shipment}/cancel',
                [ShipmentController::class, 'cancel']
            )->name('shipments.cancel');


            /*
            |--------------------------------------------------------------------------
            | SHIPMENT TASKS
            |--------------------------------------------------------------------------
            */

            Route::get(
                'shipment-tasks',
                [AdminShipmentTaskController::class, 'index']
            )->name('shipment-tasks.index');

            Route::post(
                'shipment-tasks/{id}/assign',
                [AdminShipmentTaskController::class, 'assign']
            )->name('shipment-tasks.assign');

            Route::post(
                'shipment-tasks/{id}/status',
                [AdminShipmentTaskController::class, 'updateStatus']
            )->name('shipment-tasks.status');


            /*
            |--------------------------------------------------------------------------
            | NOTIFICATIONS
            |--------------------------------------------------------------------------
            */

            Route::get(
                'notifications',
                [AdminNotificationController::class, 'index']
            )->name('notifications.index');

            Route::post(
                'notifications/{id}/read',
                [AdminNotificationController::class, 'markRead']
            )->name('notifications.read');

            Route::post(
                'notifications/read-all',
                [AdminNotificationController::class, 'markAllRead'
            ])->name('notifications.read-all');
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

            Route::post(
                'shipments',
                [MerchantShipmentController::class, 'store']
            )->name('shipments.store');
        });
    });


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
| merchant.api-key middleware populates:
|
| request()->attributes->get('merchant_id')
|
| Shipment creation and pickup creation are intentionally separate.
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
        | CREATE SHIPMENT
        |--------------------------------------------------------------------------
        |
        | Creates:
        |
        | awaiting_pickup
        |
        | Does NOT create pickup request.
        |
        */

        Route::post(
            'shipments',
            [GatewayShipmentController::class, 'store']
        )->name('shipments.store');


        /*
        |--------------------------------------------------------------------------
        | GET SHIPMENT
        |--------------------------------------------------------------------------
        */

        Route::get(
            'shipments/{trackingNumber}',
            [GatewayShipmentController::class, 'show']
        )->name('shipments.show');


        /*
        |--------------------------------------------------------------------------
        | CANCEL SHIPMENT
        |--------------------------------------------------------------------------
        */

        Route::post(
            'shipments/{trackingNumber}/cancel',
            [GatewayShipmentController::class, 'cancel']
        )->name('shipments.cancel');
    });