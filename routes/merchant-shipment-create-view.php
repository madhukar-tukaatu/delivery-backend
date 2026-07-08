<?php

use Illuminate\Support\Facades\Route;
use Modules\Shipment\Http\Controllers\MerchantShipmentCreateViewController;

Route::prefix('v1/merchant')
    ->name('merchant.')
    ->middleware(['auth:sanctum', 'role:merchant'])
    ->group(function () {
        Route::get('pickup-locations', [MerchantShipmentCreateViewController::class, 'pickupLocations'])
            ->name('pickup-locations.index');

        Route::post('shipments/quote', [MerchantShipmentCreateViewController::class, 'quote'])
            ->name('shipments.quote');

        Route::post('shipments', [MerchantShipmentCreateViewController::class, 'store'])
            ->name('shipments.store');

        Route::get('shipments/{shipment}', [MerchantShipmentCreateViewController::class, 'show'])
            ->name('shipments.show');
    });
