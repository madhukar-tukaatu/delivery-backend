<?php

use Illuminate\Support\Facades\Route;
use Modules\Delivery\Http\Controllers\DeliveryController;
use Modules\Delivery\Http\Controllers\DeliveryRunController;
use Modules\Delivery\Http\Controllers\StaffDeliveryController;

/*
|--------------------------------------------------------------------------
| Admin / Branch Delivery Routes
|--------------------------------------------------------------------------
| NOTE: the route.permission middleware derives the required permission from
| the ROUTE NAME (via RoutePermissionMapper), not from any argument. So he
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

        /*
        | DR delivery runs (last-mile containers), shown inside the Deliveries
        | page (/admin/deliveries), so no separate menu item. Route names end in an action
        | that maps to an existing permission:
        |   runs.index / stats / ready.index / riders.index / show -> deliveries.view
        |   runs.*.assign (create, add/remove, rider, sequence, dispatch, close, cancel) -> deliveries.assign
        */
        Route::get('deliveries/runs', [DeliveryRunController::class, 'index'])
            ->middleware(['route.permission'])
            ->name('deliveries.runs.index');

        Route::get('deliveries/runs/stats', [DeliveryRunController::class, 'stats'])
            ->middleware(['route.permission'])
            ->name('deliveries.runs.stats');

        Route::get('deliveries/runs/ready', [DeliveryRunController::class, 'ready'])
            ->middleware(['route.permission'])
            ->name('deliveries.runs.ready.index');

        Route::get('deliveries/runs/riders', [DeliveryRunController::class, 'riders'])
            ->middleware(['route.permission'])
            ->name('deliveries.runs.riders.index');

        Route::get('deliveries/runs/lookup', [DeliveryRunController::class, 'lookup'])
            ->middleware(['route.permission'])
            ->name('deliveries.runs.lookup.show');

        Route::get('deliveries/runs/{run}', [DeliveryRunController::class, 'show'])
            ->whereNumber('run')
            ->middleware(['route.permission'])
            ->name('deliveries.runs.show');

        Route::post('deliveries/runs', [DeliveryRunController::class, 'store'])
            ->middleware(['route.permission'])
            ->name('deliveries.runs.store.assign');

        Route::post('deliveries/runs/{run}/items', [DeliveryRunController::class, 'addItems'])
            ->whereNumber('run')
            ->middleware(['route.permission'])
            ->name('deliveries.runs.items.add.assign');

        Route::post('deliveries/runs/{run}/items/remove', [DeliveryRunController::class, 'removeItems'])
            ->whereNumber('run')
            ->middleware(['route.permission'])
            ->name('deliveries.runs.items.remove.assign');

        Route::post('deliveries/runs/{run}/rider', [DeliveryRunController::class, 'assignRider'])
            ->whereNumber('run')
            ->middleware(['route.permission'])
            ->name('deliveries.runs.rider.assign');

        Route::post('deliveries/runs/{run}/sequence', [DeliveryRunController::class, 'sequence'])
            ->whereNumber('run')
            ->middleware(['route.permission'])
            ->name('deliveries.runs.sequence.assign');

        Route::post('deliveries/runs/{run}/dispatch', [DeliveryRunController::class, 'dispatch'])
            ->whereNumber('run')
            ->middleware(['route.permission'])
            ->name('deliveries.runs.dispatch.assign');

        Route::post('deliveries/runs/{run}/close', [DeliveryRunController::class, 'close'])
            ->whereNumber('run')
            ->middleware(['route.permission'])
            ->name('deliveries.runs.close.assign');

        Route::post('deliveries/runs/{run}/cancel', [DeliveryRunController::class, 'cancel'])
            ->whereNumber('run')
            ->middleware(['route.permission'])
            ->name('deliveries.runs.cancel.assign');

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

        // DR runs assigned to the rider (deliveries.view / deliveries.status).
        Route::get('deliveries/runs', [DeliveryRunController::class, 'staffIndex'])
            ->middleware(['route.permission'])
            ->name('deliveries.runs.index');

        Route::get('deliveries/runs/{run}', [DeliveryRunController::class, 'staffShow'])
            ->whereNumber('run')
            ->middleware(['route.permission'])
            ->name('deliveries.runs.show');

        Route::post('deliveries/runs/{run}/start', [DeliveryRunController::class, 'staffStart'])
            ->whereNumber('run')
            ->middleware(['route.permission'])
            ->name('deliveries.runs.start.status');

        Route::get('deliveries', [StaffDeliveryController::class, 'index'])
            ->middleware(['route.permission'])
            ->name('deliveries.index');

        Route::post('deliveries/{delivery}/accept', [StaffDeliveryController::class, 'accept'])
            ->middleware(['route.permission'])
            ->name('deliveries.accept');

        Route::post('deliveries/{delivery}/out-for-delivery', [StaffDeliveryController::class, 'outForDelivery'])
            ->middleware(['route.permission'])
            ->name('deliveries.out-for-delivery');

        Route::post('deliveries/{delivery}/arrived', [StaffDeliveryController::class, 'arrived'])
            ->middleware(['route.permission'])
            ->name('deliveries.arrived');

        Route::post('deliveries/{delivery}/pod-payment', [StaffDeliveryController::class, 'createPodPayment'])
            ->middleware(['route.permission'])
            ->name('deliveries.pod-payment');

        Route::get('deliveries/{delivery}/pod-payment', [StaffDeliveryController::class, 'podPayment'])
            ->middleware(['route.permission'])
            ->name('deliveries.pod-payment.status');

        // Thin aliases for older clients
        Route::post('deliveries/{delivery}/payment-session', [StaffDeliveryController::class, 'createPaymentSession'])
            ->middleware(['route.permission'])
            ->name('deliveries.payment-session');

        Route::get('deliveries/{delivery}/payment-session', [StaffDeliveryController::class, 'paymentSession'])
            ->middleware(['route.permission'])
            ->name('deliveries.payment-session.status');

        Route::post('deliveries/{delivery}/delivered', [StaffDeliveryController::class, 'delivered'])
            ->middleware(['route.permission'])
            ->name('deliveries.delivered');

        Route::post('deliveries/{delivery}/failed', [StaffDeliveryController::class, 'failed'])
            ->middleware(['route.permission'])
            ->name('deliveries.failed');
    });
