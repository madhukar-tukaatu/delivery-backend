<?php

use Illuminate\Support\Facades\Route;
use Modules\Tracking\Http\Controllers\PublicTrackingController;

Route::prefix('v1/public')->group(function () {
    Route::get('track/{trackingNumber}', [PublicTrackingController::class, 'show']);
});
