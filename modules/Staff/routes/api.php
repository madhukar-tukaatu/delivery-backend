<?php

use Illuminate\Support\Facades\Route;
use Modules\Staff\Http\Controllers\StaffController;

/*
|--------------------------------------------------------------------------
| Admin Staff Management
|--------------------------------------------------------------------------
|
| Branch Managers operate staff belonging to their own branch.
|
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware([
        'auth:sanctum',
    ])
    ->group(function () {

        Route::prefix('staff')
            ->name('staff.')
            ->group(function () {

                /*
                |--------------------------------------------------------------------------
                | Assignable staff roles
                |--------------------------------------------------------------------------
                */

                Route::get('roles', [
                    StaffController::class,
                    'roles',
                ])
                    ->middleware([
                        'permission:staff.view',
                    ])
                    ->name('roles');

                /*
                |--------------------------------------------------------------------------
                | Staff list
                |--------------------------------------------------------------------------
                */

                Route::get('/', [
                    StaffController::class,
                    'index',
                ])
                    ->middleware([
                        'permission:staff.view',
                    ])
                    ->name('index');

                /*
                |--------------------------------------------------------------------------
                | Staff details
                |--------------------------------------------------------------------------
                */

                Route::get('{staff}', [
                    StaffController::class,
                    'show',
                ])
                    ->middleware([
                        'permission:staff.view',
                    ])
                    ->name('show');

                /*
                |--------------------------------------------------------------------------
                | Create
                |--------------------------------------------------------------------------
                */

                Route::post('/', [
                    StaffController::class,
                    'store',
                ])
                    ->middleware([
                        'permission:staff.create',
                    ])
                    ->name('store');

                /*
                |--------------------------------------------------------------------------
                | Update
                |--------------------------------------------------------------------------
                */

                Route::put('{staff}', [
                    StaffController::class,
                    'update',
                ])
                    ->middleware([
                        'permission:staff.edit',
                    ])
                    ->name('update');

                /*
                |--------------------------------------------------------------------------
                | Delete / deactivate
                |--------------------------------------------------------------------------
                */

                Route::delete('{staff}', [
                    StaffController::class,
                    'destroy',
                ])
                    ->middleware([
                        'permission:staff.delete',
                    ])
                    ->name('destroy');

                /*
                |--------------------------------------------------------------------------
                | Activate / deactivate
                |--------------------------------------------------------------------------
                */

                Route::patch('{staff}/status', [
                    StaffController::class,
                    'toggleStatus',
                ])
                    ->middleware([
                        'permission:staff.status',
                    ])
                    ->name('status');
            });
    });