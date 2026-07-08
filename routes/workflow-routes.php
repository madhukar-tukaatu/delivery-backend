<?php

use Illuminate\Support\Facades\Route;
use Modules\Delivery\Http\Controllers\StaffDeliveryController;
use Modules\Dispatch\Http\Controllers\RouteWorkflowController;
use Modules\Pickup\Http\Controllers\StaffPickupController;

/*
|--------------------------------------------------------------------------
| Courier workflow routes
|--------------------------------------------------------------------------
| Add these routes to your module route files or include this file from routes/api.php.
*/

Route::middleware(['auth:sanctum'])->prefix('v1/staff')->group(function () {
    Route::get('pickups', [StaffPickupController::class, 'index']);
    Route::post('pickups/{pickup}/picked-up', [StaffPickupController::class, 'pickedUp']);
    Route::post('pickups/{pickup}/failed', [StaffPickupController::class, 'failed']);

    Route::get('deliveries', [StaffDeliveryController::class, 'index']);
    Route::post('deliveries/{delivery}/out-for-delivery', [StaffDeliveryController::class, 'outForDelivery']);
    Route::post('deliveries/{delivery}/delivered', [StaffDeliveryController::class, 'delivered']);
    Route::post('deliveries/{delivery}/failed', [StaffDeliveryController::class, 'failed']);
});

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::post('shipments/{shipment}/receive-origin-sub-branch', [RouteWorkflowController::class, 'receiveOriginSubBranch']);
    Route::post('shipments/{shipment}/dispatch-next-step', [RouteWorkflowController::class, 'dispatchNextStep']);
    Route::post('shipments/{shipment}/receive-current-step', [RouteWorkflowController::class, 'receiveCurrentStep']);
});
