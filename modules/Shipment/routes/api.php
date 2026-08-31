<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Shipment\Http\Controllers\Api\AdminNotificationController;
use Modules\Shipment\Http\Controllers\Api\AdminShipmentTaskController;
use Modules\Shipment\Http\Controllers\Api\AdminPickupController;
use Modules\Shipment\Http\Controllers\Api\AdminStaffController;
use Modules\Shipment\Http\Controllers\Api\MerchantShipmentController;
use Modules\Shipment\Http\Controllers\GatewayShipmentController;
use Modules\Shipment\Http\Controllers\ShipmentController;

use Modules\Shipment\Http\Controllers\Api\StaffPickupController;
use Modules\Shipment\Http\Controllers\Api\StaffDeliveryController;

/*
|--------------------------------------------------------------------------
| Shipment API Routes
|--------------------------------------------------------------------------
*/


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
            | Pickups
            |--------------------------------------------------------------------------
            */

            Route::get(
                'pickups',
                [StaffPickupController::class, 'index']
            )->name('pickups.index');

            Route::post(
                'pickups/{pickup}/accept',
                [StaffPickupController::class, 'accept']
            )->name('pickups.accept');

            Route::post(
                'pickups/{pickup}/start',
                [StaffPickupController::class, 'start']
            )->name('pickups.start');

            Route::post(
                'pickups/{pickup}/arrive',
                [StaffPickupController::class, 'arrive']
            )->name('pickups.arrive');

            Route::post(
                'pickups/{pickup}/shipments/{shipment}/collect',
                [StaffPickupController::class, 'collect']
            )->name('pickups.collect');

            Route::post(
                'pickups/{pickup}/complete',
                [StaffPickupController::class, 'complete']
            )->name('pickups.complete');


            /*
            |--------------------------------------------------------------------------
            | Deliveries
            |--------------------------------------------------------------------------
            */

            Route::get(
                'deliveries',
                [StaffDeliveryController::class, 'index']
            )->name('deliveries.index');

            Route::post(
                'deliveries/{delivery}/accept',
                [StaffDeliveryController::class, 'accept']
            )->name('deliveries.accept');

            Route::post(
                'deliveries/{delivery}/out-for-delivery',
                [StaffDeliveryController::class, 'outForDelivery']
            )->name('deliveries.out-for-delivery');

            Route::post(
                'deliveries/{delivery}/delivered',
                [StaffDeliveryController::class, 'delivered']
            )->name('deliveries.delivered');

            Route::post(
                'deliveries/{delivery}/failed',
                [StaffDeliveryController::class, 'failed']
            )->name('deliveries.failed');
        });
    });

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
            | Staff
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
            | Pickups
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
            | Shipments
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
            | Shipment Tasks
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
            | Notifications
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
                [AdminNotificationController::class, 'markAllRead']
            )->name('notifications.read-all');
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

            Route::post(
                'shipments',
                [MerchantShipmentController::class, 'store']
            )->name('shipments.store');
        });
    });


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
| merchant.api-key middleware must populate:
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
        | Create shipment
        |--------------------------------------------------------------------------
        |
        | Creates:
        |
        | awaiting_pickup
        |
        | It does NOT create a pickup request.
        |
        */

        Route::post(
            'shipments',
            [GatewayShipmentController::class, 'store']
        )->name('shipments.store');


        /*
        |--------------------------------------------------------------------------
        | Get shipment
        |--------------------------------------------------------------------------
        */

        Route::get(
            'shipments/{trackingNumber}',
            [GatewayShipmentController::class, 'show']
        )->name('shipments.show');


        /*
        |--------------------------------------------------------------------------
        | Cancel shipment
        |--------------------------------------------------------------------------
        */

        Route::post(
            'shipments/{trackingNumber}/cancel',
            [GatewayShipmentController::class, 'cancel']
        )->name('shipments.cancel');
    });


