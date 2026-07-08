<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RiderLocationController;

/*
|--------------------------------------------------------------------------
| Broadcasting Auth Route
|--------------------------------------------------------------------------
| Put this file in routes/api.php using:
| require base_path('routes/realtime-routes.php');
|
| If this file is loaded from routes/api.php, the endpoint becomes:
| POST /api/broadcasting/auth
*/

Broadcast::routes(['middleware' => ['auth:sanctum']]);

Route::prefix('v1/staff')
    ->middleware(['auth:sanctum'])
    ->group(function () {
        Route::post('rider-location', [RiderLocationController::class, 'store'])
            ->name('staff.rider-location.store');
    });
