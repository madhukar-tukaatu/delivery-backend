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

        // NOTE: Roles route MUST come before staff/{staff} to avoid matching {staff} = "roles"
        // Using /roles as sub-route to avoid conflict with {staff} parameter
        Route::get(
            'staff/roles',
            [StaffController::class, 'roles']
        )
            ->middleware([
                'route.permission',
            ])
            ->name('staff.roles');

        // NOTE: Staff show route uses where constraint to only match numeric IDs
        // This prevents "roles" from being matched as {staff}
        Route::get(
            'staff/{staff}',
            [StaffController::class, 'show']
        )
            ->middleware([
                'route.permission',
            ])
            ->name('staff.show')
            ->where('staff', '[0-9]+');

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
            ->name('staff.update')
            ->where('staff', '[0-9]+');

        Route::patch(
            'staff/{staff}/toggle',
            [StaffController::class, 'toggle']
        )
            ->middleware([
                'route.permission',
            ])
            ->name('staff.toggle')
            ->where('staff', '[0-9]+');

        Route::delete(
            'staff/{staff}',
            [StaffController::class, 'destroy']
        )
            ->middleware([
                'route.permission',
            ])
            ->name('staff.destroy')
            ->where('staff', '[0-9]+');
    });