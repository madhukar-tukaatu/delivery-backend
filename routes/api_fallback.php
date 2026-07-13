<?php
// Copy into routes/api.php only if module routes are not appearing in php artisan route:list.
use Illuminate\Support\Facades\Route;
use Modules\Rate\Http\Controllers\Api\AdminPricingTestController;
use Modules\Rate\Http\Controllers\Api\AdminSetupCrudController;
use Modules\Rate\Http\Controllers\Api\PublicPricingQuoteController;
use Modules\Shipment\Http\Controllers\Api\AdminNotificationController;
use Modules\Shipment\Http\Controllers\Api\AdminShipmentController;
use Modules\Shipment\Http\Controllers\Api\AdminShipmentTaskController;
use Modules\Shipment\Http\Controllers\Api\PublicShipmentController;
Route::prefix('v1/public')->group(function(){ Route::post('/pricing/quote',[PublicPricingQuoteController::class,'store']); Route::post('/shipments',[PublicShipmentController::class,'store']); });
Route::prefix('v1/admin')->middleware(['auth:sanctum'])->group(function(){
    Route::post('/pricing/test',[AdminPricingTestController::class,'test']);
    Route::get('/service-types',[AdminSetupCrudController::class,'serviceTypes']); Route::post('/service-types',[AdminSetupCrudController::class,'saveServiceType']);
    Route::get('/branch-pricing-rules',[AdminSetupCrudController::class,'branchPricing']); Route::post('/branch-pricing-rules',[AdminSetupCrudController::class,'saveBranchPricing']);
    Route::get('/branch-transfer-lanes',[AdminSetupCrudController::class,'transferLanes']); Route::post('/branch-transfer-lanes',[AdminSetupCrudController::class,'saveTransferLane']);
    Route::get('/shipments',[AdminShipmentController::class,'index']); Route::get('/shipments/{id}',[AdminShipmentController::class,'show']);
    Route::get('/shipment-tasks',[AdminShipmentTaskController::class,'index']); Route::post('/shipment-tasks/{id}/assign',[AdminShipmentTaskController::class,'assign']); Route::post('/shipment-tasks/{id}/status',[AdminShipmentTaskController::class,'updateStatus']);
    Route::get('/notifications',[AdminNotificationController::class,'index']); Route::post('/notifications/{id}/read',[AdminNotificationController::class,'markRead']); Route::post('/notifications/read-all',[AdminNotificationController::class,'markAllRead']);
});
