<?php

declare (strict_types = 1);

use Illuminate\Support\Facades\Route;
use Modules\Shipment\Http\Controllers\Api\AdminNotificationController;
use Modules\Shipment\Http\Controllers\Api\AdminShipmentTaskController;
use Modules\Shipment\Http\Controllers\Api\AdminStaffController;
use Modules\Shipment\Http\Controllers\Api\MerchantShipmentController;
use Modules\Shipment\Http\Controllers\GatewayShipmentController;
use Modules\Shipment\Http\Controllers\ShipmentController;
use Modules\Shipment\Http\Controllers\StaffDeliveryLifecycleController;
use Modules\Shipment\Http\Controllers\TransferController;

/*
|--------------------------------------------------------------------------
| Shipment API Routes
|--------------------------------------------------------------------------
|
| Shipment module owns:
|
| - Shipments
| - Shipment status
| - Delivery lifecycle
| - Staff
| - Shipment administration
|
| Pickup lifecycle belongs to the Pickup module.
|
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| STAFF
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| STAFF DELIVERIES
|--------------------------------------------------------------------------
| Moved to modules/Delivery/routes/api.php (single source of truth:
| StaffDeliveryController + DeliveryWorkflowService). Removed here to avoid
| duplicate v1/staff/deliveries/* URI + route-name registration.
|--------------------------------------------------------------------------
*/

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
            | TRANSFERS (branch-to-branch)
            |--------------------------------------------------------------------------
            | Route names use a unique "transfers" module (RoutePermissionMapper
            | aliases transfers -> dispatches) so they don't collide with the
            | Dispatch module's dispatches.* route names, while still mapping to
            | existing permissions:
            |   transfers.index / summary -> dispatches.view
            |   transfers.dispatch        -> dispatches.dispatch
            |   transfers.receive         -> dispatches.receive
            |--------------------------------------------------------------------------
            */

            Route::get('transfers', [TransferController::class, 'index'])
                ->name('transfers.index');

            Route::get('transfers/summary', [TransferController::class, 'summary'])
                ->name('transfers.summary');

            Route::post('transfers/dispatch', [TransferController::class, 'dispatch'])
                ->name('transfers.dispatch');

            Route::post('transfers/{shipment}/receive', [TransferController::class, 'receive'])
                ->name('transfers.receive');

            /*
            |--------------------------------------------------------------------------
            | STAFF
            |--------------------------------------------------------------------------
            */

            Route::get(
                'staff',
                [
                    AdminStaffController::class,
                    'index',
                ]
            )->name('staff.index');

            Route::post(
                'staff',
                [
                    AdminStaffController::class,
                    'store',
                ]
            )->name('staff.store');

            Route::get(
                'staff/{staff}',
                [
                    AdminStaffController::class,
                    'show',
                ]
            )->name('staff.show');

            Route::put(
                'staff/{staff}',
                [
                    AdminStaffController::class,
                    'update',
                ]
            )->name('staff.update');

            Route::delete(
                'staff/{staff}',
                [
                    AdminStaffController::class,
                    'destroy',
                ]
            )->name('staff.destroy');

            Route::post(
                'staff/{staff}/toggle',
                [
                    AdminStaffController::class,
                    'toggle',
                ]
            )->name('staff.toggle');

            /*
            |--------------------------------------------------------------------------
            | SHIPMENTS
            |--------------------------------------------------------------------------
            */

            Route::get(
                'shipments',
                [
                    ShipmentController::class,
                    'index',
                ]
            )->name('shipments.index');

            Route::post(
                'shipments',
                [
                    ShipmentController::class,
                    'store',
                ]
            )->name('shipments.store');

            Route::get(
                'shipments/{shipment}',
                [
                    ShipmentController::class,
                    'show',
                ]
            )->name('shipments.show');

            Route::put(
                'shipments/{shipment}',
                [
                    ShipmentController::class,
                    'update',
                ]
            )->name('shipments.update');

            Route::post(
                'shipments/{shipment}/status',
                [
                    ShipmentController::class,
                    'status',
                ]
            )->name('shipments.status');

            Route::post(
                'shipments/{shipment}/cancel',
                [
                    ShipmentController::class,
                    'cancel',
                ]
            )->name('shipments.cancel');

            /*
            |--------------------------------------------------------------------------
            | SHIPMENT TASKS
            |--------------------------------------------------------------------------
            */

            Route::get(
                'shipment-tasks',
                [
                    AdminShipmentTaskController::class,
                    'index',
                ]
            )->name('shipment-tasks.index');

            Route::post(
                'shipment-tasks/{id}/assign',
                [
                    AdminShipmentTaskController::class,
                    'assign',
                ]
            )->name('shipment-tasks.assign');

            Route::post(
                'shipment-tasks/{id}/status',
                [
                    AdminShipmentTaskController::class,
                    'updateStatus',
                ]
            )->name('shipment-tasks.status');

            /*
            |--------------------------------------------------------------------------
            | NOTIFICATIONS
            |--------------------------------------------------------------------------
            */

            Route::get(
                'notifications',
                [
                    AdminNotificationController::class,
                    'index',
                ]
            )->name('notifications.index');

            Route::post(
                'notifications/{id}/read',
                [
                    AdminNotificationController::class,
                    'markRead',
                ]
            )->name('notifications.read');

            Route::post(
                'notifications/read-all',
                [
                    AdminNotificationController::class,
                    'markAllRead',
                ]
            )->name('notifications.read-all');
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
                [
                    MerchantShipmentController::class,
                    'store',
                ]
            )->name('shipments.store');
        });
    });

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
        | CREATE SHIPMENT
        |--------------------------------------------------------------------------
        |
        | This NEVER creates a pickup.
        |
        | If an open pickup already exists, the new shipment is
        | automatically attached to it by ShipmentService.
        |
        */

        Route::post(
            'shipments',
            [
                GatewayShipmentController::class,
                'store',
            ]
        )->name('shipments.store');

        /*
        |--------------------------------------------------------------------------
        | GET SHIPMENT
        |--------------------------------------------------------------------------
        */

        Route::get(
            'shipments/{trackingNumber}',
            [
                GatewayShipmentController::class,
                'show',
            ]
        )->name('shipments.show');

        /*
        |--------------------------------------------------------------------------
        | CANCEL SHIPMENT
        |--------------------------------------------------------------------------
        */

        Route::post(
            'shipments/{trackingNumber}/cancel',
            [
                GatewayShipmentController::class,
                'cancel',
            ]
        )->name('shipments.cancel');
    });
