<?php

use Illuminate\Support\Facades\Route;
use Modules\Shipment\Http\Controllers\Api\AdminNotificationController;
use Modules\Shipment\Http\Controllers\Api\AdminShipmentTaskController;
use Modules\Shipment\Http\Controllers\GatewayShipmentController;
use Modules\Shipment\Http\Controllers\MerchantShipmentController;
use Modules\Shipment\Http\Controllers\ShipmentController;

/*
|--------------------------------------------------------------------------
| Admin Shipment Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum', 'branch.scope'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Shipments (Admin)
            |--------------------------------------------------------------------------
            | Auto generated permissions:
            | shipments.view
            | shipments.create
            | shipments.edit
            | shipments.status
            | shipments.cancel
            */

            Route::get('shipments', [ShipmentController::class, 'index'])
                ->name('shipments.index');

            Route::post('shipments', [ShipmentController::class, 'store'])
                ->name('shipments.store');

            Route::get('shipments/{shipment}', [ShipmentController::class, 'show'])
                ->name('shipments.show');

            Route::put('shipments/{shipment}', [ShipmentController::class, 'update'])
                ->name('shipments.update');

            Route::post('shipments/{shipment}/status', [ShipmentController::class, 'status'])
                ->name('shipments.status');

            Route::post('shipments/{shipment}/cancel', [ShipmentController::class, 'cancel'])
                ->name('shipments.cancel');

            Route::get('/shipment-tasks', [AdminShipmentTaskController::class, 'index']);
            Route::post('/shipment-tasks/{id}/assign', [AdminShipmentTaskController::class, 'assign']);
            Route::post('/shipment-tasks/{id}/status', [AdminShipmentTaskController::class, 'updateStatus']);
            Route::get('/notifications', [AdminNotificationController::class, 'index']);
            Route::post('/notifications/{id}/read', [AdminNotificationController::class, 'markRead']);
            Route::post('/notifications/read-all', [AdminNotificationController::class, 'markAllRead']);
        });
    });

/*
|--------------------------------------------------------------------------
| Merchant Shipment Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/merchant')
    ->name('merchant.')
    ->middleware(['auth:sanctum', 'role:merchant', 'branch.scope'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Shipments (Merchant)
            |--------------------------------------------------------------------------
            | Permissions:
            | merchant.shipments OR shipments.view
            */

            Route::get('shipments', [MerchantShipmentController::class, 'index'])
                ->name('shipments.index');

            Route::post('shipments', [MerchantShipmentController::class, 'store'])
                ->name('shipments.store');

            Route::post('shipments/quote', [MerchantShipmentController::class, 'quote'])
                ->name('shipments.quote');

            Route::get('shipments/{trackingNumber}', [MerchantShipmentController::class, 'show'])
                ->name('shipments.show');

            Route::post('shipments/{trackingNumber}/cancel', [MerchantShipmentController::class, 'cancel'])
                ->name('shipments.cancel');
        });
    });

/*
|--------------------------------------------------------------------------
| Gateway Shipment Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/gateway')
    ->name('gateway.')
    ->middleware(['gateway.auth'])
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Shipments (Gateway)
        |--------------------------------------------------------------------------
        | External system access (no route.permission)
        */

        Route::post('shipments', [GatewayShipmentController::class, 'store'])
            ->name('shipments.store');

        Route::get('shipments/{trackingNumber}', [GatewayShipmentController::class, 'show'])
            ->name('shipments.show');

        Route::post('shipments/{trackingNumber}/cancel', [GatewayShipmentController::class, 'cancel'])
            ->name('shipments.cancel');
    });
