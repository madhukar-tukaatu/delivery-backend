<?php

use Illuminate\Support\Facades\Route;
use Modules\Merchant\Http\Controllers\AdminMerchantApplicationController;
use Modules\Merchant\Http\Controllers\MerchantOnboardingController;
use Modules\Merchant\Http\Controllers\PublicMerchantSignupController;
use Modules\Shipment\Http\Controllers\MerchantShipmentController;

/*
| Important for this project:
| routes/api.php is already under Laravel's /api prefix.
| So use v1/..., NOT api/v1/...
*/

Route::prefix('v1/public')->group(function () {
    Route::post('merchant-signup', [PublicMerchantSignupController::class, 'store']);
});

Route::prefix('v1/merchant')
    ->name('merchant.')
    ->middleware(['auth:sanctum', 'role:merchant', 'route.permission'])
    ->group(function () {
        Route::get('onboarding', [MerchantOnboardingController::class, 'show'])
            ->name('merchant-onboarding.show');

        Route::post('onboarding/business-profile', [MerchantOnboardingController::class, 'businessProfile'])
            ->name('merchant-onboarding.business-profile');

        Route::post('onboarding/pickup-location', [MerchantOnboardingController::class, 'pickupLocation'])
            ->name('merchant-onboarding.pickup-location');

        Route::post('onboarding/bank-details', [MerchantOnboardingController::class, 'bankDetails'])
            ->name('merchant-onboarding.bank-details');

        Route::post('onboarding/documents', [MerchantOnboardingController::class, 'uploadDocument'])
            ->name('merchant-onboarding.documents');

        Route::post('onboarding/submit', [MerchantOnboardingController::class, 'submit'])
            ->name('merchant-onboarding.submit');

        Route::get('shipments', [MerchantShipmentController::class, 'index'])
            ->name('shipments.index');

        Route::post('shipments', [MerchantShipmentController::class, 'store'])
            ->name('shipments.store');

        Route::get('shipments/{shipment}', [MerchantShipmentController::class, 'show'])
            ->name('shipments.show');
    });

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum', 'route.permission'])
    ->group(function () {
        Route::get('merchant-applications', [AdminMerchantApplicationController::class, 'index'])
            ->name('merchant-applications.index');

        Route::get('merchant-applications/{merchant}', [AdminMerchantApplicationController::class, 'show'])
            ->name('merchant-applications.show');

        Route::post('merchant-applications/{merchant}/approve', [AdminMerchantApplicationController::class, 'approve'])
            ->name('merchant-applications.approve');

        Route::post('merchant-applications/{merchant}/reject', [AdminMerchantApplicationController::class, 'reject'])
            ->name('merchant-applications.reject');

        Route::post('merchant-applications/{merchant}/request-more-info', [AdminMerchantApplicationController::class, 'requestMoreInfo'])
            ->name('merchant-applications.request-more-info');
    });
