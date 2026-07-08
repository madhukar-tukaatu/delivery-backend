<?php

use Illuminate\Support\Facades\Route;
use Modules\Dispatch\Http\Controllers\DispatchController;

/*
|--------------------------------------------------------------------------
| Admin Dispatch Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum', 'branch.scope'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Dispatches
            |--------------------------------------------------------------------------
            | Auto generated permissions:
            | dispatches.view
            | dispatches.create
            | dispatches.receive
            */

            Route::get('dispatches', [DispatchController::class, 'index'])
                ->name('dispatches.index');

            Route::post('dispatches', [DispatchController::class, 'store'])
                ->name('dispatches.store');

            Route::post('dispatches/{dispatch}/receive', [DispatchController::class, 'receive'])
                ->name('dispatches.receive');
        });
    });