<?php

use Illuminate\Support\Facades\Route;
use Modules\Access\Http\Controllers\MenuController;

/*
|--------------------------------------------------------------------------
| Dynamic Menu Routes
|--------------------------------------------------------------------------
| Do NOT protect /me/menus with route.permission.
| Sidebar needs this endpoint to load after login.
*/

Route::prefix('v1')
    ->middleware(['auth:sanctum'])
    ->group(function () {
        Route::get('me/menus', [MenuController::class, 'my'])
            ->name('me.menus');
    });

/*
| Optional backward-compatible route if your frontend currently calls
| /api/v1/admin/me/menus.
*/
Route::prefix('v1/admin')
    ->middleware(['auth:sanctum'])
    ->group(function () {
        Route::get('me/menus', [MenuController::class, 'my'])
            ->name('admin.me.menus');
    });

/*
| Admin Menu CRUD - protected by automatic route permission.
| Permissions generated:
| menus.view, menus.create, menus.edit, menus.delete
*/
Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum', 'route.permission'])
    ->group(function () {
        Route::apiResource('menus', MenuController::class)
            ->except(['show'])
            ->names([
                'index' => 'menus.index',
                'store' => 'menus.store',
                'update' => 'menus.update',
                'destroy' => 'menus.destroy',
            ]);
    });
