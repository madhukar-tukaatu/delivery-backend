<?php

use Illuminate\Support\Facades\Route;

use Modules\Merchant\Http\Controllers\AdminMerchantApplicationController;
use Modules\Merchant\Http\Controllers\AdminMerchantChangeRequestController;
use Modules\Merchant\Http\Controllers\ApiLogController;
use Modules\Merchant\Http\Controllers\MerchantApiKeyController;
use Modules\Merchant\Http\Controllers\MerchantChangeRequestController;
use Modules\Merchant\Http\Controllers\MerchantController;
use Modules\Merchant\Http\Controllers\MerchantDocumentController;
use Modules\Merchant\Http\Controllers\MerchantOnboardingController;
use Modules\Merchant\Http\Controllers\MerchantWebhookController;
use Modules\Merchant\Http\Controllers\PublicMerchantSignupController;
use Modules\Merchant\Http\Controllers\StoreIntegrationApplicationController;

use Modules\Shipment\Http\Controllers\MerchantShipmentController;

/*
|--------------------------------------------------------------------------
| Merchant Document Routes
|--------------------------------------------------------------------------
|
| Authenticated document preview/download.
|
*/

Route::prefix('v1')
    ->middleware(['auth:sanctum'])
    ->group(function () {
        Route::get(
            'merchant-documents/{document}/preview',
            [MerchantDocumentController::class, 'preview']
        )->name('merchant-documents.preview');

        Route::get(
            'merchant-documents/{document}/download',
            [MerchantDocumentController::class, 'download']
        )->name('merchant-documents.download');
    });


/*
|--------------------------------------------------------------------------
| Public Merchant Routes
|--------------------------------------------------------------------------
|
| These routes do not require authentication.
|
*/

Route::prefix('v1/merchant')
    ->name('merchant.public.')
    ->group(function () {
        Route::post(
            'signup',
            [PublicMerchantSignupController::class, 'store']
        )->name('signup');
    });


/*
|--------------------------------------------------------------------------
| Store Manager Integration Routes
|--------------------------------------------------------------------------
|
| Store Manager submits merchant integration applications and change requests.
|
*/

Route::prefix('v1/store-integrations')
    ->name('store-integrations.')
    ->middleware([
        'store.integration.token',
        'throttle:20,1',
    ])
    ->group(function () {
        Route::post(
            'applications/{applicationNumber}/submit',
            [StoreIntegrationApplicationController::class, 'submit']
        )
            ->where(
                'applicationNumber',
                '[A-Za-z0-9._-]+'
            )
            ->name('applications.submit');

        /*
        |--------------------------------------------------------------------------
        | Store Manager Change Request Routes
        |--------------------------------------------------------------------------
        |
        | Store managers can request changes to their merchant details:
        | - Location changes
        | - Document updates
        | - Bank details changes
        | - Business profile updates
        |
        | Pattern: POST /api/v1/store-integrations/merchants/{externalStoreId}/change-request/submit
        |
        */

        Route::post(
            'merchants/{externalStoreId}/change-request/submit',
            [StoreIntegrationApplicationController::class, 'submitChangeRequest']
        )
            ->where(
                'externalStoreId',
                '[A-Za-z0-9._-]+'
            )
            ->name('change-request.submit');
    });


/*
|--------------------------------------------------------------------------
| Admin Merchant Routes
|--------------------------------------------------------------------------
|
| IMPORTANT:
|
| There are intentionally NO routes such as:
|
|   /admin/branches/{branch}/merchants
|   /admin/branches/{branch}/shipments
|
| Branch scoping should be handled server-side based on the
| authenticated user's branch.
|
| Therefore both super admins and branch users use:
|
|   GET /api/v1/admin/merchants
|   GET /api/v1/admin/shipments
|
| Super admin:
|   → sees all records
|
| Branch user:
|   → controller/query scopes records to their branch
|
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum'])
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Permission-protected Admin Routes
        |--------------------------------------------------------------------------
        */

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Merchants
            |--------------------------------------------------------------------------
            */

            Route::apiResource(
                'merchants',
                MerchantController::class
            )->names([
                'index'   => 'merchants.index',
                'store'   => 'merchants.store',
                'show'    => 'merchants.show',
                'update'  => 'merchants.update',
                'destroy' => 'merchants.destroy',
            ]);

            Route::post(
                'merchants/{merchant}/approve',
                [MerchantController::class, 'approve']
            )->name('merchants.approve');

            Route::post(
                'merchants/{merchant}/suspend',
                [MerchantController::class, 'suspend']
            )->name('merchants.suspend');


            /*
            |--------------------------------------------------------------------------
            | Merchant Applications
            |--------------------------------------------------------------------------
            */

            Route::get(
                'merchant-applications',
                [AdminMerchantApplicationController::class, 'index']
            )->name('merchant-applications.index');

            Route::get(
                'merchant-applications/{merchant}',
                [AdminMerchantApplicationController::class, 'show']
            )->name('merchant-applications.show');

            Route::post(
                'merchant-applications/{merchant}/approve',
                [AdminMerchantApplicationController::class, 'approve']
            )->name('merchant-applications.approve');

            Route::post(
                'merchant-applications/{merchant}/reject',
                [AdminMerchantApplicationController::class, 'reject']
            )->name('merchant-applications.reject');

            Route::post(
                'merchant-applications/{merchant}/request-more-info',
                [AdminMerchantApplicationController::class, 'requestMoreInfo']
            )->name('merchant-applications.request-more-info');

            Route::post(
                'merchant-applications/{merchant}/retry-callback',
                [AdminMerchantApplicationController::class, 'retryCallback']
            )->name('merchant-applications.retry-callback');


            /*
            |--------------------------------------------------------------------------
            | Merchant Change Requests (Location, Documents, Bank Details, etc.)
            |--------------------------------------------------------------------------
            */

            Route::get(
                'change-requests',
                [AdminMerchantChangeRequestController::class, 'index']
            )->name('change-requests.index');

            Route::get(
                'change-requests/{changeRequest}',
                [AdminMerchantChangeRequestController::class, 'show']
            )->name('change-requests.show');

            Route::post(
                'change-requests/{changeRequest}/approve',
                [AdminMerchantChangeRequestController::class, 'approve']
            )->name('change-requests.approve');

            Route::post(
                'change-requests/{changeRequest}/reject',
                [AdminMerchantChangeRequestController::class, 'reject']
            )->name('change-requests.reject');

            Route::post(
                'change-requests/{changeRequest}/start-review',
                [AdminMerchantChangeRequestController::class, 'startReview']
            )->name('change-requests.start-review');

            Route::get(
                'merchants-with-suspended-services',
                [AdminMerchantChangeRequestController::class, 'getMerchantsWithSuspendedServices']
            )->name('merchants-with-suspended-services');


            /*
            |--------------------------------------------------------------------------
            | Shipments
            |--------------------------------------------------------------------------
            |
            | Same endpoint for all admin users.
            |
            | Super admin:
            |   → all shipments
            |
            | Branch user:
            |   → branch-scoped shipments
            |
            */

            Route::get(
                'shipments',
                [MerchantShipmentController::class, 'index']
            )->name('merchant.shipments.index');

            Route::post(
                'shipments',
                [MerchantShipmentController::class, 'store']
            )->name('merchant.shipments.store');

            Route::get(
                'shipments/{shipment}',
                [MerchantShipmentController::class, 'show']
            )->name('merchant.shipments.show');


            /*
            |--------------------------------------------------------------------------
            | Merchant API Keys
            |--------------------------------------------------------------------------
            */

            Route::get(
                'api-keys',
                [MerchantApiKeyController::class, 'index']
            )->name('api-keys.index');

            Route::post(
                'api-keys',
                [MerchantApiKeyController::class, 'store']
            )->name('api-keys.store');

            Route::delete(
                'api-keys/{apiKey}',
                [MerchantApiKeyController::class, 'destroy']
            )->name('api-keys.destroy');


            /*
            |--------------------------------------------------------------------------
            | Merchant Webhooks
            |--------------------------------------------------------------------------
            */

            Route::get(
                'webhooks',
                [MerchantWebhookController::class, 'index']
            )->name('webhooks.index');

            Route::post(
                'webhooks',
                [MerchantWebhookController::class, 'store']
            )->name('webhooks.store');

            Route::delete(
                'webhooks/{webhook}',
                [MerchantWebhookController::class, 'destroy']
            )->name('webhooks.destroy');


            /*
            |--------------------------------------------------------------------------
            | API Logs
            |--------------------------------------------------------------------------
            */

            Route::get(
                'api-logs',
                [ApiLogController::class, 'index']
            )->name('api-logs.index');
        });
    });


/*
|--------------------------------------------------------------------------
| Merchant Onboarding Routes
|--------------------------------------------------------------------------
|
| New merchants may not have a branch yet, so these routes intentionally
| remain outside branch.scope.
|
*/

Route::prefix('v1/merchant')
    ->name('merchant.onboarding.')
    ->middleware([
        'auth:sanctum',
        'role:merchant',
    ])
    ->group(function () {

        Route::get(
            'onboarding',
            [MerchantOnboardingController::class, 'show']
        )->name('show');

        Route::post(
            'onboarding/business-profile',
            [MerchantOnboardingController::class, 'businessProfile']
        )->name('business-profile');

        Route::post(
            'onboarding/pickup-location',
            [MerchantOnboardingController::class, 'pickupLocation']
        )->name('pickup-location');

        Route::post(
            'onboarding/bank-details',
            [MerchantOnboardingController::class, 'bankDetails']
        )->name('bank-details');

        Route::post(
            'onboarding/documents',
            [MerchantOnboardingController::class, 'uploadDocument']
        )->name('documents');

        Route::post(
            'onboarding/submit',
            [MerchantOnboardingController::class, 'submit']
        )->name('submit');
    });


/*
|--------------------------------------------------------------------------
| Merchant Change Request Routes
|--------------------------------------------------------------------------
|
| Merchants can request changes to their details (location, documents, etc.)
| Services are granularly suspended based on what changed.
|
| Pattern follows Store Manager integrations:
|   POST /api/v1/merchant/change-requests/{merchantId}/submit
|
*/

Route::prefix('v1/merchant')
    ->name('merchant.change-requests.')
    ->middleware([
        'auth:sanctum',
        'role:merchant',
    ])
    ->group(function () {

        // Get merchant's service status and pending changes
        Route::get(
            'service-status',
            [MerchantChangeRequestController::class, 'getServiceStatus']
        )->name('service-status');

        // List all change requests for this merchant
        Route::get(
            'change-requests',
            [MerchantChangeRequestController::class, 'index']
        )->name('index');

        // Get a specific change request
        Route::get(
            'change-requests/{changeRequest}',
            [MerchantChangeRequestController::class, 'show']
        )->name('show');

        // Unified submit endpoint for all change types
        // POST /api/v1/merchant/change-requests/{merchantId}/submit
        Route::post(
            'change-requests/{merchant}/submit',
            [MerchantChangeRequestController::class, 'submit']
        )->where('merchant', '[0-9]+')
            ->name('submit');

        // Cancel a pending change request
        Route::post(
            'change-requests/{changeRequest}/cancel',
            [MerchantChangeRequestController::class, 'cancel']
        )->name('cancel');
    });


/*
|--------------------------------------------------------------------------
| Merchant Self-Service Routes
|--------------------------------------------------------------------------
|
| These routes are for authenticated merchants.
| branch.scope remains enabled here.
|
*/

Route::prefix('v1/merchant')
    ->name('merchant.')
    ->middleware([
        'auth:sanctum',
        'role:merchant',
        'branch.scope',
    ])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | API Keys
            |--------------------------------------------------------------------------
            */

            Route::get(
                'api-keys',
                [MerchantApiKeyController::class, 'index']
            )->name('api-keys.index');

            Route::post(
                'api-keys',
                [MerchantApiKeyController::class, 'store']
            )->name('api-keys.store');

            Route::delete(
                'api-keys/{apiKey}',
                [MerchantApiKeyController::class, 'destroy']
            )->name('api-keys.destroy');


            /*
            |--------------------------------------------------------------------------
            | Webhooks
            |--------------------------------------------------------------------------
            */

            Route::get(
                'webhooks',
                [MerchantWebhookController::class, 'index']
            )->name('webhooks.index');

            Route::post(
                'webhooks',
                [MerchantWebhookController::class, 'store']
            )->name('webhooks.store');

            Route::delete(
                'webhooks/{webhook}',
                [MerchantWebhookController::class, 'destroy']
            )->name('webhooks.destroy');


            /*
            |--------------------------------------------------------------------------
            | API Logs
            |--------------------------------------------------------------------------
            */

            Route::get(
                'api-logs',
                [ApiLogController::class, 'index']
            )->name('api-logs.index');
        });
    });