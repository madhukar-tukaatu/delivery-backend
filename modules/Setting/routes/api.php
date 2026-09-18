<?php

use Illuminate\Support\Facades\Route;
use Modules\Setting\Http\Controllers\BackupController;
use Modules\Setting\Http\Controllers\SettingController;

/*
|--------------------------------------------------------------------------
| Admin Setting Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Settings
            |--------------------------------------------------------------------------
            | Auto generated permissions:
            | settings.view
            | settings.manage
            */

            Route::get('settings', [SettingController::class, 'index'])
                ->name('settings.index');

            Route::post('settings', [SettingController::class, 'store'])
                ->name('settings.store');
        });

        /*
        |--------------------------------------------------------------------------
        | Backup Routes
        |--------------------------------------------------------------------------
        | Permissions required:
        | backups.view - List backups
        | backups.create - Create backup
        | backups.download - Download backup
        | backups.delete - Delete backup
        */

        Route::get('backups', [BackupController::class, 'index'])
            ->name('backups.index')
            ->adminMenu('Backups', '/admin/backups', 'database', 161);

        Route::post('backups', [BackupController::class, 'store'])
            ->name('backups.store');

        Route::get('backups/{filename}', [BackupController::class, 'download'])
            ->name('backups.download');

        Route::delete('backups/{filename}', [BackupController::class, 'destroy'])
            ->name('backups.destroy');

        Route::post('backups/cleanup', [BackupController::class, 'cleanup'])
            ->name('backups.cleanup');

        Route::get('backups/schedules', [BackupController::class, 'schedules'])
            ->name('backups.schedules');

        Route::put('backups/schedules/{id}', [BackupController::class, 'updateSchedule'])
            ->name('backups.updateSchedule');

        Route::post('backups/trigger', [BackupController::class, 'triggerBackup'])
            ->name('backups.trigger');
    });