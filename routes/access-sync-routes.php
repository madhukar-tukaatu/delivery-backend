<?php

use Illuminate\Support\Facades\Route;
use Modules\Access\Http\Controllers\AccessSyncController;

/*
| Add this file using require base_path('routes/access-sync-routes.php');
| inside routes/api.php.
|
| Laravel already prefixes api.php with /api, so use v1/admin here.
*/

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum', 'route.permission'])
    ->group(function () {
        Route::post('access/sync', [AccessSyncController::class, 'sync'])
            ->name('access.sync');
    });
