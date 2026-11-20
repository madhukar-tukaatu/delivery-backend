<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Tracking\Http\Controllers\MarketplaceTrackingController;
use Modules\Tracking\Http\Controllers\PublicTrackingController;

/*
|--------------------------------------------------------------------------
| Public Tracking Routes
|--------------------------------------------------------------------------
*/
Route::prefix('v1/public')
    ->name('public.tracking.')
    ->group(function (): void {
        Route::get('track/{trackingNumber}', [PublicTrackingController::class, 'show'])->name('track');
        Route::get('tracking/{trackingNumber}', [PublicTrackingController::class, 'show'])->name('show');
        Route::get('track/{trackingNumber}/live', [PublicTrackingController::class, 'live'])->name('track.live');
        Route::get('tracking/{trackingNumber}/live', [PublicTrackingController::class, 'live'])->name('live');
    });

Route::prefix('v1')
    ->group(function (): void {
        Route::get('track/{trackingNumber}', [PublicTrackingController::class, 'show']);
        Route::get('tracking/{trackingNumber}', [PublicTrackingController::class, 'show']);
        Route::get('track/{trackingNumber}/live', [PublicTrackingController::class, 'live']);
        Route::get('tracking/{trackingNumber}/live', [PublicTrackingController::class, 'live']);
    });

/*
|--------------------------------------------------------------------------
| Partner Marketplace Tracking Routes (HMAC Authenticated)
|--------------------------------------------------------------------------
| Headers:
| X-Tukaatu-Marketplace-Key: <key>
| X-Tukaatu-Timestamp: <unix_ts>
| X-Tukaatu-Request-Id: <uuid>
| X-Tukaatu-Signature: <hmac_sha256>
*/
Route::prefix('v1/marketplace/tracking')
    ->name('marketplace.tracking.')
    ->middleware([
        'marketplace.api-key:tracking',
        'throttle:60,1',
    ])
    ->group(function (): void {
        Route::get('{trackingNumber}', [MarketplaceTrackingController::class, 'show'])->name('show');
        Route::get('{trackingNumber}/live', [MarketplaceTrackingController::class, 'live'])->name('live');
        Route::get('order/{orderId}', [MarketplaceTrackingController::class, 'byOrder'])->name('by-order');
    });
