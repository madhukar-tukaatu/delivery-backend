<?php

use Illuminate\Support\Facades\Route;
use Modules\Pickup\Http\Controllers\GatewayPickupController;
use Modules\Shipment\Http\Controllers\Api\AdminNotificationController;
use Modules\Shipment\Http\Controllers\Api\AdminShipmentTaskController;
use Modules\Shipment\Http\Controllers\Api\MerchantShipmentController;
use Modules\Shipment\Http\Controllers\GatewayShipmentController;
use Modules\Shipment\Http\Controllers\ShipmentController;

/*
|--------------------------------------------------------------------------
| Admin Shipment Routes
|--------------------------------------------------------------------------
|
| Used by Tukaatu administrative users.
|
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware([
        'auth:sanctum',
        // 'branch.scope',
    ])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

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
            );

            Route::post(
                'shipment-tasks/{id}/assign',
                [AdminShipmentTaskController::class, 'assign']
            );

            Route::post(
                'shipment-tasks/{id}/status',
                [AdminShipmentTaskController::class, 'updateStatus']
            );

            /*
            |--------------------------------------------------------------------------
            | Notifications
            |--------------------------------------------------------------------------
            */

            Route::get(
                'notifications',
                [AdminNotificationController::class, 'index']
            );

            Route::post(
                'notifications/{id}/read',
                [AdminNotificationController::class, 'markRead']
            );

            Route::post(
                'notifications/read-all',
                [AdminNotificationController::class, 'markAllRead']
            );
        });
    });

/*
|--------------------------------------------------------------------------
| Tukaatu Internal Merchant Routes
|--------------------------------------------------------------------------
|
| These are merchants who registered directly with Tukaatu.
|
| Authentication:
|   Laravel Sanctum
|
| Example:
|
|   POST /api/v1/merchant/shipments
|
*/

Route::prefix('v1/merchant')
    ->name('merchant.')
    ->middleware([
        'auth:sanctum',
        'role:merchant',
        'branch.scope',
    ])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            Route::post(
                'shipments',
                [MerchantShipmentController::class, 'store']
            )->name('shipments.store');
        });
    });

/*
|--------------------------------------------------------------------------
| External Store Manager / Integration Routes
|--------------------------------------------------------------------------
|
| These are stores that integrate their own Store Manager system
| with Tukaatu.
|
| Authentication:
|
|   X-Tukaatu-Key
|   X-Tukaatu-Secret
|
| IMPORTANT:
|
| No Sanctum.
| No role:merchant.
| No branch.scope.
| No route.permission.
|
*/

Route::prefix('v1/gateway')
    ->name('gateway.')
    ->middleware([
        'merchant.api-key',
    ])
    ->group(function () {

        Route::post(
            'shipments',
            [GatewayShipmentController::class, 'store']
        )->name('shipments.store');

        Route::get(
            'shipments/{trackingNumber}',
            [GatewayShipmentController::class, 'show']
        )->name('shipments.show');

        Route::post(
            'shipments/{trackingNumber}/cancel',
            [GatewayShipmentController::class, 'cancel']
        )->name('shipments.cancel');

        Route::post(
            'pickups',
            [GatewayPickupController::class, 'store']
        )->name('pickups.store');

        Route::get(
            'pickups/{requestNumber}',
            [GatewayPickupController::class, 'show']
        )->name('pickups.show');
    });
