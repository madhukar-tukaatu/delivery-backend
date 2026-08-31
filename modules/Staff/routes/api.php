<?php

use Illuminate\Support\Facades\Route;
use Modules\Staff\Http\Controllers\StaffController;

/*
|--------------------------------------------------------------------------
| Admin Staff Routes
|--------------------------------------------------------------------------
|
| Permission convention:
|
| staff.view
| staff.create
| staff.update
| staff.delete
| staff.toggle
|
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
            */

            Route::get('staff', [
                StaffController::class,
                'index',
            ])->name('staff.index');

            Route::post('staff', [
                StaffController::class,
                'store',
            ])->name('staff.store');

            Route::get('staff/{staff}', [
                StaffController::class,
                'show',
            ])->name('staff.show');

            Route::put('staff/{staff}', [
                StaffController::class,
                'update',
            ])->name('staff.update');

            Route::patch('staff/{staff}', [
                StaffController::class,
                'update',
            ])->name('staff.update.patch');

            Route::delete('staff/{staff}', [
                StaffController::class,
                'destroy',
            ])->name('staff.destroy');

            Route::patch('staff/{staff}/toggle', [
                StaffController::class,
                'toggle',
            ])->name('staff.toggle');
        });
    });