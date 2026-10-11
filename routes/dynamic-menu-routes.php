<?php

use Illuminate\Support\Facades\Route;
use Modules\Access\Http\Controllers\MenuBadgeController;
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

        // Sidebar badge counts (work waiting per menu). Each badge checks its own page permission.
        Route::get('me/menu-badges', [MenuBadgeController::class, 'index'])
            ->name('me.menu-badges');
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
| menus.view, menus.create, menus.update, menus.delete
| reorder maps to menus.update via RoutePermissionMapper.
*/
Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum', 'route.permission'])
    ->group(function () {
        Route::post('menus/reorder', [MenuController::class, 'reorder'])
            ->name('menus.reorder');

        Route::apiResource('menus', MenuController::class)
            ->except(['show'])
            ->names([
                'index' => 'menus.index',
                'store' => 'menus.store',
                'update' => 'menus.update',
                'destroy' => 'menus.destroy',
            ]);
    });
