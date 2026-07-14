<?php

use Illuminate\Support\Facades\Route;
use Modules\Access\Http\Controllers\MenuController;
use Modules\Rate\Http\Controllers\Api\PublicPricingQuoteController;
use Modules\Shipment\Http\Controllers\Api\PublicShipmentController;

require base_path('routes/realtime-routes.php');
require base_path('routes/delivery-lifecycle.php');

/*
|--------------------------------------------------------------------------
| Health Check
|--------------------------------------------------------------------------
*/

Route::get('/v1/health', function () {
    return response()->json([
        'ok' => true,
        'app' => config('app.name'),
        'time' => now()->toIso8601String(),
    ]);
});

/*
|--------------------------------------------------------------------------
| Core Shared Routes
|--------------------------------------------------------------------------
| Keep these before module routes.
*/

require base_path('routes/workflow-routes.php');
require base_path('routes/merchant-shipment-create-view.php');

/*
|--------------------------------------------------------------------------
| Dynamic Menu Route
|--------------------------------------------------------------------------
| Use only one source for /v1/me/menus.
| If routes/dynamic-menu-routes.php already defines /v1/me/menus,
| then do not define it again here.
|--------------------------------------------------------------------------
*/

// Option A: If dynamic-menu-routes.php already has /v1/me/menus, keep this:
require base_path('routes/dynamic-menu-routes.php');
require base_path('routes/api_fallback.php');

// Option B: If you want to define it directly here instead, remove the require above
// and uncomment this block:
//
// Route::prefix('v1')
//     ->middleware(['auth:sanctum'])
//     ->group(function () {
//         Route::get('me/menus', [MenuController::class, 'my'])
//             ->name('me.menus');
//     });

/*
|--------------------------------------------------------------------------
| Module API Routes
|--------------------------------------------------------------------------
*/

foreach (glob(base_path('modules/*/routes/api.php')) as $routeFile) {
    require $routeFile;
}

Route::prefix('v1/public')->group(function () {
    Route::post('/pricing/quote', [PublicPricingQuoteController::class, 'store']);
    Route::post('/shipments', [PublicShipmentController::class, 'store']);
});