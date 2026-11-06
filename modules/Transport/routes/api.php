<?php

use Illuminate\Support\Facades\Route;
use Modules\Transport\Http\Controllers\TransportTripController;
use Modules\Transport\Http\Controllers\TransportVehicleController;

Route::prefix('v1/transport')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function () {
        // Summary & Sizing
        Route::get('summary', [TransportTripController::class, 'summary']);
        Route::post('recommend-fleet', [TransportVehicleController::class, 'recommend']);

        // Vehicles
        Route::get('vehicles', [TransportVehicleController::class, 'index']);
        Route::post('vehicles', [TransportVehicleController::class, 'store']);
        Route::get('vehicles/{id}', [TransportVehicleController::class, 'show']);
        Route::put('vehicles/{id}', [TransportVehicleController::class, 'update']);

        // Drivers
        Route::get('drivers', [TransportVehicleController::class, 'drivers']);
        Route::post('drivers', [TransportVehicleController::class, 'storeDriver']);

        // Line-haul Trips
        Route::get('trips', [TransportTripController::class, 'index']);
        Route::post('trips', [TransportTripController::class, 'store']);
        Route::get('trips/{id}', [TransportTripController::class, 'show']);
        Route::post('trips/{id}/dispatch', [TransportTripController::class, 'dispatch']);
        Route::post('trips/{id}/complete', [TransportTripController::class, 'complete']);
    });

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function () {
        Route::prefix('transport')->group(function () {
            Route::get('summary', [TransportTripController::class, 'summary'])
                ->name('transport.summary')
                ->adminMenu('Transport Fleet', '/admin/transport', 'truck', 66, 'Operations');

            Route::post('recommend-fleet', [TransportVehicleController::class, 'recommend']);
            Route::get('vehicles', [TransportVehicleController::class, 'index']);
            Route::post('vehicles', [TransportVehicleController::class, 'store']);
            Route::get('vehicles/{id}', [TransportVehicleController::class, 'show']);
            Route::put('vehicles/{id}', [TransportVehicleController::class, 'update']);

            Route::get('drivers', [TransportVehicleController::class, 'drivers']);
            Route::post('drivers', [TransportVehicleController::class, 'storeDriver']);

            Route::get('trips', [TransportTripController::class, 'index']);
            Route::post('trips', [TransportTripController::class, 'store']);
            Route::get('trips/{id}', [TransportTripController::class, 'show']);
            Route::post('trips/{id}/dispatch', [TransportTripController::class, 'dispatch']);
            Route::post('trips/{id}/complete', [TransportTripController::class, 'complete']);
        });
    });