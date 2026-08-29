<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Shipment\Http\Controllers\Api\AdminNotificationController;
use Modules\Shipment\Http\Controllers\Api\AdminShipmentTaskController;
use Modules\Shipment\Http\Controllers\Api\MerchantShipmentController;
use Modules\Shipment\Http\Controllers\GatewayShipmentController;
use Modules\Shipment\Http\Controllers\ShipmentController;

/*
|--------------------------------------------------------------------------
| Shipment API Routes
|--------------------------------------------------------------------------
|
|--------------------------------------------------------------------------
*/


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
    ->group(function (): void {

        Route::middleware([
            'route.permission',
        ])->group(function (): void {

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
| Tukaatu Internal Merchant Routes
|--------------------------------------------------------------------------
|
| Merchants registered directly with Tukaatu.
|
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
|   X-Tukaatu-Key
|   X-Tukaatu-Secret
|
| merchant.api-key middleware must populate:
|
|   request()->attributes->get('merchant_id')
|
| IMPORTANT:
|
| Pickup routes are NOT registered here.
|
| Pickup belongs to the Pickup module.
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
        | Store Manager creates the shipment.
        |
        | Result:
        |
        | awaiting_pickup
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