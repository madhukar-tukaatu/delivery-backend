<?php

use Illuminate\Support\Facades\Route;
use Modules\Staff\Http\Controllers\StaffController;

/*
|--------------------------------------------------------------------------
| Admin Staff Routes
|--------------------------------------------------------------------------
|
| These routes generate the following permissions:
|
| staff.view
| staff.create
| staff.edit
| staff.delete
| staff.status
|
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Staff - View
            |--------------------------------------------------------------------------
            */

            Route::get('staff', [
                StaffController::class,
                'index',
            ])
                ->middleware('permission:staff.view')
                ->name('staff.index');

            /*
            |--------------------------------------------------------------------------
            | Staff - Create
            |--------------------------------------------------------------------------
            */

            Route::post('staff', [
                StaffController::class,
                'store',
            ])
                ->middleware('permission:staff.create')
                ->name('staff.store');

            /*
            |--------------------------------------------------------------------------
            | Staff - View Single
            |--------------------------------------------------------------------------
            */

            Route::get('staff/{staff}', [
                StaffController::class,
                'show',
            ])
                ->middleware('permission:staff.view')
                ->name('staff.show');

            /*
            |--------------------------------------------------------------------------
            | Staff - Edit
            |--------------------------------------------------------------------------
            */

            Route::put('staff/{staff}', [
                StaffController::class,
                'update',
            ])
                ->middleware('permission:staff.edit')
                ->name('staff.update');

            /*
            |--------------------------------------------------------------------------
            | Staff - Delete / Deactivate
            |--------------------------------------------------------------------------
            */

            Route::delete('staff/{staff}', [
                StaffController::class,
                'destroy',
            ])
                ->middleware('permission:staff.delete')
                ->name('staff.destroy');

            /*
            |--------------------------------------------------------------------------
            | Staff - Status
            |--------------------------------------------------------------------------
            */

            Route::patch('staff/{staff}/status', [
                StaffController::class,
                'toggleStatus',
            ])
                ->middleware('permission:staff.status')
                ->name('staff.status');
        });
    });