<?php

use Illuminate\Support\Facades\Route;
use Modules\Staff\Http\Controllers\StaffController;

/*
|--------------------------------------------------------------------------
| Admin Staff Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Staff
            |--------------------------------------------------------------------------
            | Auto generated permissions:
            | users.view
            | users.manage
            */

            Route::get('staff', [StaffController::class, 'index'])
                ->name('staff.index');

            Route::post('staff', [StaffController::class, 'store'])
                ->name('staff.store');
        });
    });