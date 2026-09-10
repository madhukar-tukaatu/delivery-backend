<?php

use Illuminate\Support\Facades\Route;
use Modules\Delivery\Http\Controllers\DeliveryController;
use Modules\Delivery\Http\Controllers\StaffDeliveryController;

/*
|--------------------------------------------------------------------------
| Admin / Branch Delivery Routes
|--------------------------------------------------------------------------
| NOTE: the route.permission middleware derives the required permission from
| the ROUTE NAME (via RoutePermissionMapper), not from any argument. So the
| route name's last segment must map to an existing permission:
|   deliveries.index / summary        -> deliveries.view
|   deliveries.bulk-assign             -> deliveries.assign
|   deliveries.assignable-riders       -> deliveries.assign
|   deliveries.assign                  -> deliveries.assign
|   deliveries.failed                  -> deliveries.failed
|   deliveries.accept                  -> deliveries.accept
|   deliveries.out-for-delivery        -> deliveries.status
|   deliveries.delivered               -> deliveries.delivered
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum', 'branch.scope'])
    ->group(function () {

        Route::get('deliveries', [DeliveryController::class, 'index'])
            ->middleware(['route.permission'])
            ->name('deliveries.index');

        Route::get('deliveries/summary', [DeliveryController::class, 'summary'])
            ->middleware(['route.permission'])
            ->name('deliveries.summary');

        Route::post('deliveries/bulk-assign', [DeliveryController::class, 'bulkAssign'])
            ->middleware(['route.permission'])
            ->name('deliveries.bulk-assign');

        Route::get('deliveries/{delivery}/assignable-riders', [DeliveryController::class, 'assignableRiders'])
            ->middleware(['route.permission'])
            ->name('deliveries.assignable-riders');

        Route::post('deliveries/{delivery}/assign', [DeliveryController::class, 'assign'])
            ->middleware(['route.permission'])
            ->name('deliveries.assign');

        Route::post('deliveries/{delivery}/failed', [DeliveryController::class, 'failed'])
            ->middleware(['route.permission'])
            ->name('deliveries.failed');
    });

/*
|--------------------------------------------------------------------------
| Staff / Rider Delivery Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/staff')
    ->name('staff.')
    ->middleware(['auth:sanctum', 'branch.scope'])
    ->group(function () {

        Route::get('deliveries', [StaffDeliveryController::class, 'index'])
            ->middleware(['route.permission'])
            ->name('deliveries.index');

        Route::post('deliveries/{delivery}/accept', [StaffDeliveryController::class, 'accept'])
            ->middleware(['route.permission'])
            ->name('deliveries.accept');

        Route::post('deliveries/{delivery}/out-for-delivery', [StaffDeliveryController::class, 'outForDelivery'])
            ->middleware(['route.permission'])
            ->name('deliveries.out-for-delivery');

        Route::post('deliveries/{delivery}/delivered', [StaffDeliveryController::class, 'delivered'])
            ->middleware(['route.permission'])
            ->name('deliveries.delivered');

        Route::post('deliveries/{delivery}/failed', [StaffDeliveryController::class, 'failed'])
            ->middleware(['route.permission'])
            ->name('deliveries.failed');
    });
