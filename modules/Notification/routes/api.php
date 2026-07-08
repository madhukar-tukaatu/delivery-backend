<?php

use Illuminate\Support\Facades\Route;
use Modules\Notification\Http\Controllers\NotificationController;

/*
|--------------------------------------------------------------------------
| Admin Notification Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum'])
    ->group(function () {

        Route::middleware(['route.permission'])->group(function () {

            /*
            |--------------------------------------------------------------------------
            | Notifications
            |--------------------------------------------------------------------------
            | Auto generated permissions:
            | notifications.view
            | notifications.manage
            */

            Route::get('notifications', [NotificationController::class, 'index'])
                ->name('notifications.index');

            Route::post('notifications', [NotificationController::class, 'store'])
                ->name('notifications.store');

            Route::post('notifications/{notification}/mark-sent', [NotificationController::class, 'markSent'])
                ->name('notifications.mark-sent');

            /*
            |--------------------------------------------------------------------------
            | Logs
            |--------------------------------------------------------------------------
            */

            Route::get('sms-logs', [NotificationController::class, 'sms'])
                ->name('notifications.sms-logs');

            Route::get('email-logs', [NotificationController::class, 'emails'])
                ->name('notifications.email-logs');
        });
    });