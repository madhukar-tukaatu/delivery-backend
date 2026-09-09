<?php

use Illuminate\Support\Facades\Route;
use Modules\Delivery\Http\Controllers\DeliveryController;
use Modules\Delivery\Http\Controllers\StaffDeliveryController;

/*
|--------------------------------------------------------------------------
| Admin / Branch Delivery Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum', 'branch.scope'])
    ->group(function () {

        Route::get('deliveries', [DeliveryController::class, 'index'])
            ->middleware(['route.permission:deliveries.view'])
            ->name('deliveries.index');

        Route::get('deliveries/summary', [DeliveryController::class, 'summary'])
            ->middleware(['route.permission:deliveries.view'])
            ->name('deliveries.summary');

        Route::post('deliveries/bulk-assign', [DeliveryController::class, 'bulkAssign'])
            ->middleware(['route.permission:deliveries.assign'])
            ->name('deliveries.bulk-assign');

        Route::get('deliveries/{delivery}/assignable-riders', [DeliveryController::class, 'assignableRiders'])
            ->middleware(['route.permission:deliveries.assign'])
            ->name('deliveries.assignable-riders');

        Route::post('deliveries/{delivery}/assign', [DeliveryController::class, 'assign'])
            ->middleware(['route.permission:deliveries.assign'])
            ->name('deliveries.assign');

        Route::post('deliveries/{delivery}/failed', [DeliveryController::class, 'failed'])
            ->middleware(['route.permission:deliveries.failed'])
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
            ->middleware(['route.permission:deliveries.view'])
            ->name('deliveries.index');

        Route::post('deliveries/{delivery}/accept', [StaffDeliveryController::class, 'accept'])
            ->middleware(['route.permission:deliveries.accept'])
            ->name('deliveries.accept');

        Route::post('deliveries/{delivery}/out-for-delivery', [StaffDeliveryController::class, 'outForDelivery'])
            ->middleware(['route.permission:deliveries.out_for_delivery'])
            ->name('deliveries.out-for-delivery');

        Route::post('deliveries/{delivery}/delivered', [StaffDeliveryController::class, 'delivered'])
            ->middleware(['route.permission:deliveries.delivered'])
            ->name('deliveries.delivered');

        Route::post('deliveries/{delivery}/failed', [StaffDeliveryController::class, 'failed'])
            ->middleware(['route.permission:deliveries.failed'])
            ->name('deliveries.failed');
    });
