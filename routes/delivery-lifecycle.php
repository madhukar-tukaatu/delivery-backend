<?php

use Illuminate\Support\Facades\Route;
use Modules\Shipment\Http\Controllers\AccountsPaymentLifecycleController;
use Modules\Shipment\Http\Controllers\AdminShipmentLifecycleController;
use Modules\Shipment\Http\Controllers\MerchantShipmentLifecycleController;
use Modules\Shipment\Http\Controllers\StaffDeliveryLifecycleController;
use Modules\Shipment\Http\Controllers\StaffPickupLifecycleController;
use Modules\Shipment\Http\Controllers\StoreShipmentApiController;

/*
|--------------------------------------------------------------------------
| Complete delivery lifecycle routes
|--------------------------------------------------------------------------
| Add this in routes/api.php:
| require base_path('routes/delivery-lifecycle.php');
*/

Route::prefix('v1/store')
    ->name('store.')
    ->group(function () {
        Route::post('shipments/quote', [StoreShipmentApiController::class, 'quote'])->name('shipments.quote');
        Route::post('shipments', [StoreShipmentApiController::class, 'store'])->name('shipments.store');
        Route::get('shipments/{trackingNumber}', [StoreShipmentApiController::class, 'show'])->name('shipments.show');
    });

Route::prefix('v1/merchant')
    ->name('merchant.lifecycle.')
    ->middleware(['auth:sanctum', 'role:merchant'])
    ->group(function () {
        Route::post('shipments/quote', [MerchantShipmentLifecycleController::class, 'quote'])->name('shipments.quote');
        Route::post('shipments', [MerchantShipmentLifecycleController::class, 'store'])->name('shipments.store');
        Route::get('shipments/{shipment}', [MerchantShipmentLifecycleController::class, 'show'])->name('shipments.show');
    });

Route::prefix('v1/admin')
    ->name('admin.lifecycle.')
    ->middleware(['auth:sanctum'])
    ->group(function () {
        Route::post('shipments/quote', [AdminShipmentLifecycleController::class, 'quote'])->name('shipments.quote');
        Route::post('shipments', [AdminShipmentLifecycleController::class, 'store'])->name('shipments.store');
        Route::get('shipments/{shipment}/lifecycle', [AdminShipmentLifecycleController::class, 'lifecycle'])->name('shipments.lifecycle');
        Route::post('shipments/{shipment}/assign-pickup', [AdminShipmentLifecycleController::class, 'assignPickup'])->name('shipments.assign-pickup');
        Route::post('shipments/{shipment}/receive-origin', [AdminShipmentLifecycleController::class, 'receiveOrigin'])->name('shipments.receive-origin');
        Route::post('shipments/{shipment}/assign-delivery', [AdminShipmentLifecycleController::class, 'assignDelivery'])->name('shipments.assign-delivery');

        Route::post('shipments/transfer-batches', [AdminShipmentLifecycleController::class, 'createTransferBatch'])->name('shipments.transfer-batches.store');
        Route::post('shipments/transfer-batches/{batch}/dispatch', [AdminShipmentLifecycleController::class, 'dispatchTransferBatch'])->name('shipments.transfer-batches.dispatch');
        Route::post('shipments/transfer-batches/{batch}/receive', [AdminShipmentLifecycleController::class, 'receiveTransferBatch'])->name('shipments.transfer-batches.receive');

        Route::get('accounts/cod-collections', [AccountsPaymentLifecycleController::class, 'codCollections'])->name('accounts.cod-collections');
        Route::post('accounts/rider-deposits', [AccountsPaymentLifecycleController::class, 'riderDeposit'])->name('accounts.rider-deposits');
        Route::post('accounts/merchant-settlements', [AccountsPaymentLifecycleController::class, 'merchantSettlement'])->name('accounts.merchant-settlements');
        Route::post('accounts/merchant-settlements/{settlement}/mark-paid', [AccountsPaymentLifecycleController::class, 'markSettlementPaid'])->name('accounts.merchant-settlements.mark-paid');
    });

Route::prefix('v1/staff')
    ->name('staff.lifecycle.')
    ->middleware(['auth:sanctum'])
    ->group(function () {
        Route::get('pickups', [StaffPickupLifecycleController::class, 'index'])->name('pickups.index');
        Route::post('pickups/{pickup}/accept', [StaffPickupLifecycleController::class, 'accept'])->name('pickups.accept');
        Route::post('pickups/{pickup}/picked-up', [StaffPickupLifecycleController::class, 'pickedUp'])->name('pickups.picked-up');

        Route::get('deliveries', [StaffDeliveryLifecycleController::class, 'index'])->name('deliveries.index');
        Route::post('deliveries/{delivery}/accept', [StaffDeliveryLifecycleController::class, 'accept'])->name('deliveries.accept');
        Route::post('deliveries/{delivery}/out-for-delivery', [StaffDeliveryLifecycleController::class, 'outForDelivery'])->name('deliveries.out-for-delivery');
        Route::post('deliveries/{delivery}/delivered', [StaffDeliveryLifecycleController::class, 'delivered'])->name('deliveries.delivered');
        Route::post('deliveries/{delivery}/failed', [StaffDeliveryLifecycleController::class, 'failed'])->name('deliveries.failed');
    });
