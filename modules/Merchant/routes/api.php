<?php

use Illuminate\Support\Facades\Route;
use Modules\Merchant\Http\Controllers\AdminMerchantApplicationController;
use Modules\Merchant\Http\Controllers\ApiLogController;
use Modules\Merchant\Http\Controllers\MerchantApiKeyController;
use Modules\Merchant\Http\Controllers\MerchantController;
use Modules\Merchant\Http\Controllers\MerchantDocumentController;
use Modules\Merchant\Http\Controllers\MerchantOnboardingController;
use Modules\Merchant\Http\Controllers\MerchantWebhookController;
use Modules\Merchant\Http\Controllers\PublicMerchantSignupController;
use Modules\Shipment\Http\Controllers\MerchantShipmentController;

/*
|--------------------------------------------------------------------------
| Shared Merchant Document Routes
|--------------------------------------------------------------------------
| Used by admin and merchant to preview/download private KYC documents.
| Do NOT put inside route.permission.
| Do NOT put inside role:merchant only.
*/

Route::prefix('v1')
    ->middleware(['auth:sanctum'])
    ->group(function () {
        Route::get('merchant-documents/{document}/preview', [MerchantDocumentController::class, 'preview'])
            ->name('merchant-documents.preview');

        Route::get('merchant-documents/{document}/download', [MerchantDocumentController::class, 'download'])
            ->name('merchant-documents.download');
    });

/*
|--------------------------------------------------------------------------
| Public Merchant Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/merchant')
    ->name('merchant.public.')
    ->group(function () {
        Route::post('signup', [PublicMerchantSignupController::class, 'store'])
            ->name('signup');
    });

/*
|--------------------------------------------------------------------------
| Admin Merchant Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum'])
    ->group(function () {
        Route::middleware(['route.permission'])->group(function () {
            Route::apiResource('merchants', MerchantController::class)
                ->names([
                    'index' => 'merchants.index',
                    'store' => 'merchants.store',
                    'show' => 'merchants.show',
                    'update' => 'merchants.update',
                    'destroy' => 'merchants.destroy',
                ]);

            Route::post('merchants/{merchant}/approve', [MerchantController::class, 'approve'])
                ->name('merchants.approve');

            Route::post('merchants/{merchant}/suspend', [MerchantController::class, 'suspend'])
                ->name('merchants.suspend');

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

            Route::get('shipments', [MerchantShipmentController::class, 'index'])
                ->name('merchant.shipments.index');

            Route::post('shipments', [MerchantShipmentController::class, 'store'])
                ->name('merchant.shipments.store');

            Route::get('shipments/{shipment}', [MerchantShipmentController::class, 'show'])
                ->name('merchant.shipments.show');

            Route::get('api-keys', [MerchantApiKeyController::class, 'index'])
                ->name('api-keys.index');

            Route::post('api-keys', [MerchantApiKeyController::class, 'store'])
                ->name('api-keys.store');

            Route::delete('api-keys/{apiKey}', [MerchantApiKeyController::class, 'destroy'])
                ->name('api-keys.destroy');

            Route::get('webhooks', [MerchantWebhookController::class, 'index'])
                ->name('webhooks.index');

            Route::post('webhooks', [MerchantWebhookController::class, 'store'])
                ->name('webhooks.store');

            Route::delete('webhooks/{webhook}', [MerchantWebhookController::class, 'destroy'])
                ->name('webhooks.destroy');

            Route::get('api-logs', [ApiLogController::class, 'index'])
                ->name('api-logs.index');
        });
    });

/*
|--------------------------------------------------------------------------
| Merchant Onboarding Routes
|--------------------------------------------------------------------------
| Keep onboarding outside branch.scope because new merchants may not have branch yet.
*/

Route::prefix('v1/merchant')
    ->name('merchant.onboarding.')
    ->middleware(['auth:sanctum', 'role:merchant'])
    ->group(function () {
        Route::get('onboarding', [MerchantOnboardingController::class, 'show'])
            ->name('show');

        Route::post('onboarding/business-profile', [MerchantOnboardingController::class, 'businessProfile'])
            ->name('business-profile');

        Route::post('onboarding/pickup-location', [MerchantOnboardingController::class, 'pickupLocation'])
            ->name('pickup-location');

        Route::post('onboarding/bank-details', [MerchantOnboardingController::class, 'bankDetails'])
            ->name('bank-details');

        Route::post('onboarding/documents', [MerchantOnboardingController::class, 'uploadDocument'])
            ->name('documents');

        Route::post('onboarding/submit', [MerchantOnboardingController::class, 'submit'])
            ->name('submit');
    });

/*
|--------------------------------------------------------------------------
| Merchant Self-Service Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/merchant')
    ->name('merchant.')
    ->middleware(['auth:sanctum', 'role:merchant', 'branch.scope'])
    ->group(function () {
        Route::middleware(['route.permission'])->group(function () {
            Route::get('api-keys', [MerchantApiKeyController::class, 'index'])
                ->name('api-keys.index');

            Route::post('api-keys', [MerchantApiKeyController::class, 'store'])
                ->name('api-keys.store');

            Route::delete('api-keys/{apiKey}', [MerchantApiKeyController::class, 'destroy'])
                ->name('api-keys.destroy');

            Route::get('webhooks', [MerchantWebhookController::class, 'index'])
                ->name('webhooks.index');

            Route::post('webhooks', [MerchantWebhookController::class, 'store'])
                ->name('webhooks.store');

            Route::delete('webhooks/{webhook}', [MerchantWebhookController::class, 'destroy'])
                ->name('webhooks.destroy');

            Route::get('api-logs', [ApiLogController::class, 'index'])
                ->name('api-logs.index');
        });
    });