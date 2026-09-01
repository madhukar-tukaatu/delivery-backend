<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Staff\Http\Controllers\StaffController;

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware([
        'auth:sanctum',
        'branch.scope',
    ])
    ->group(function (): void {

        /*
        |--------------------------------------------------------------------------
        | Branch Staff
        |--------------------------------------------------------------------------
        */

        Route::get(
            'staff',
            [StaffController::class, 'index']
        )
            ->middleware([
                'route.permission',
            ])
            ->name('staff.index');

        Route::get(
            'staff/roles',
            [StaffController::class, 'roles']
        )
            ->middleware([
                'route.permission',
            ])
            ->name('staff.roles');

        Route::get(
            'staff/{staff}',
            [StaffController::class, 'show']
        )
            ->middleware([
                'route.permission',
            ])
            ->name('staff.show');

        Route::post(
            'staff',
            [StaffController::class, 'store']
        )
            ->middleware([
                'route.permission',
            ])
            ->name('staff.store');

        Route::put(
            'staff/{staff}',
            [StaffController::class, 'update']
        )
            ->middleware([
                'route.permission',
            ])
            ->name('staff.update');

        Route::patch(
            'staff/{staff}/toggle',
            [StaffController::class, 'toggle']
        )
            ->middleware([
                'route.permission',
            ])
            ->name('staff.toggle');

        Route::delete(
            'staff/{staff}',
            [StaffController::class, 'destroy']
        )
            ->middleware([
                'route.permission',
            ])
            ->name('staff.destroy');
    });