<?php

use Illuminate\Support\Facades\Route;
use Modules\Shipment\Http\Controllers\AccountsPaymentOperationsController;
use Modules\Shipment\Http\Controllers\AdminShipmentLifecycleController ;
use Modules\Shipment\Http\Controllers\BranchParcelOperationsController;
use Modules\Shipment\Http\Controllers\MerchantShipmentOperationsController;
use Modules\Shipment\Http\Controllers\StaffDeliveryOperationsController;
use Modules\Shipment\Http\Controllers\StaffPickupOperationsController;
use Modules\Shipment\Http\Controllers\StoreShipmentApiController;

/*
|--------------------------------------------------------------------------
| Merchant shipment operations
|--------------------------------------------------------------------------
*/

Route::prefix('v1/merchant')
    ->name('merchant.operations.')
    ->middleware(['auth:sanctum', 'role:merchant'])
    ->group(function () {
        Route::get('pickup-locations', [MerchantShipmentOperationsController::class, 'pickupLocations'])
            ->name('pickup-locations.index');

        Route::post('shipments/quote', [MerchantShipmentOperationsController::class, 'quote'])
            ->name('shipments.quote');

        Route::get('shipments', [MerchantShipmentOperationsController::class, 'index'])
            ->name('shipments.index');

        Route::post('shipments', [MerchantShipmentOperationsController::class, 'store'])
            ->name('shipments.store');

        Route::get('shipments/{shipment}', [MerchantShipmentOperationsController::class, 'show'])
            ->name('shipments.show');
    });

/*
|--------------------------------------------------------------------------
| Store API shipment operations
|--------------------------------------------------------------------------
*/

Route::prefix('v1/store')
    ->name('store.operations.')
    ->group(function () {
        Route::post('shipments/quote', [StoreShipmentApiController::class, 'quote'])
            ->name('shipments.quote');

        Route::post('shipments', [StoreShipmentApiController::class, 'store'])
            ->name('shipments.store');

        Route::get('shipments/{trackingNumber}', [StoreShipmentApiController::class, 'show'])
            ->name('shipments.show');
    });

/*
|--------------------------------------------------------------------------
| Admin shipment operations
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->name('admin.operations.')
    ->middleware(['auth:sanctum'])
    ->group(function () {
        Route::post('shipments/quote', [AdminShipmentLifecycleController ::class, 'quote'])
            ->name('shipments.quote');

        Route::post('shipments', [AdminShipmentLifecycleController ::class, 'store'])
            ->name('shipments.store');

        Route::get('shipments/{shipment}', [AdminShipmentLifecycleController ::class, 'show'])
            ->name('shipments.show');

        Route::post('shipments/{shipment}/assign-pickup', [AdminShipmentLifecycleController ::class, 'assignPickup'])
            ->name('shipments.assign-pickup');

        Route::post('shipments/{shipment}/receive-origin', [BranchParcelOperationsController::class, 'receiveOrigin'])
            ->name('shipments.receive-origin');

        Route::post('shipments/{shipment}/create-transfer', [BranchParcelOperationsController::class, 'createTransfer'])
            ->name('shipments.create-transfer');

        Route::post('transfers/{batch}/dispatch', [BranchParcelOperationsController::class, 'dispatchTransfer'])
            ->name('transfers.dispatch');

        Route::post('transfers/{batch}/receive', [BranchParcelOperationsController::class, 'receiveTransfer'])
            ->name('transfers.receive');

        Route::post('shipments/{shipment}/assign-delivery', [AdminShipmentLifecycleController ::class, 'assignDelivery'])
            ->name('shipments.assign-delivery');

        Route::get('accounts/cod-pending', [AccountsPaymentOperationsController::class, 'codPending'])
            ->name('accounts.cod-pending');

        Route::post('accounts/cod/{cod}/confirm-deposit', [AccountsPaymentOperationsController::class, 'confirmDeposit'])
            ->name('accounts.cod.confirm-deposit');

        Route::get('accounts/settlements', [AccountsPaymentOperationsController::class, 'settlements'])
            ->name('accounts.settlements.index');

        Route::post('accounts/settlements', [AccountsPaymentOperationsController::class, 'createSettlement'])
            ->name('accounts.settlements.store');

        Route::post('accounts/settlements/{settlement}/mark-paid', [AccountsPaymentOperationsController::class, 'markSettlementPaid'])
            ->name('accounts.settlements.mark-paid');
    });

/*
|--------------------------------------------------------------------------
| Staff pickup and delivery operations
|--------------------------------------------------------------------------
*/

Route::prefix('v1/staff')
    ->name('staff.operations.')
    ->middleware(['auth:sanctum'])
    ->group(function () {
        Route::get('pickups', [StaffPickupOperationsController::class, 'index'])
            ->name('pickups.index');

        Route::post('pickups/{pickup}/accept', [StaffPickupOperationsController::class, 'accept'])
            ->name('pickups.accept');

        Route::post('pickups/{pickup}/picked-up', [StaffPickupOperationsController::class, 'pickedUp'])
            ->name('pickups.picked-up');

        Route::get('deliveries', [StaffDeliveryOperationsController::class, 'index'])
            ->name('deliveries.index');

        Route::post('deliveries/{delivery}/accept', [StaffDeliveryOperationsController::class, 'accept'])
            ->name('deliveries.accept');

        Route::post('deliveries/{delivery}/out-for-delivery', [StaffDeliveryOperationsController::class, 'outForDelivery'])
            ->name('deliveries.out-for-delivery');

        Route::post('deliveries/{delivery}/delivered', [StaffDeliveryOperationsController::class, 'delivered'])
            ->name('deliveries.delivered');

        Route::post('deliveries/{delivery}/failed', [StaffDeliveryOperationsController::class, 'failed'])
            ->name('deliveries.failed');
    });
