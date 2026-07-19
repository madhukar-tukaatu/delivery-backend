<?php

use Illuminate\Support\Facades\Route;
use Modules\Branch\Http\Controllers\AdminCoverageLocationController;
use Modules\Branch\Http\Controllers\BranchAgreementController;
use Modules\Branch\Http\Controllers\BranchController;
use Modules\Branch\Http\Controllers\BranchDocumentController;

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum'])
    ->group(function () {
        Route::get('branches/parent-options', [BranchController::class, 'parentOptions'])
            ->name('branches.parent-options');
        Route::get('coverage-locations/map', [AdminCoverageLocationController::class, 'map']);
        Route::apiResource('coverage-locations', AdminCoverageLocationController::class);
        Route::middleware(['route.permission'])->group(function () {
            Route::apiResource('branches', BranchController::class)
                ->names([
                    'index' => 'branches.index',
                    'store' => 'branches.store',
                    'show' => 'branches.show',
                    'update' => 'branches.update',
                    'destroy' => 'branches.destroy',
                ]);

            Route::post('branches/{branch}/approve', [BranchController::class, 'approve'])
                ->name('branches.approve');

            Route::post('branches/{branch}/reject', [BranchController::class, 'reject'])
                ->name('branches.reject');

            Route::post('branches/{branch}/suspend', [BranchController::class, 'suspend'])
                ->name('branches.suspend');

            Route::post('branches/{branch}/activate', [BranchController::class, 'activate'])
                ->name('branches.activate');

            Route::post('branches/{branch}/documents', [BranchDocumentController::class, 'store'])
                ->name('branch-documents.store');

            Route::get('branch-documents/{document}/preview', [BranchDocumentController::class, 'preview'])
                ->name('branch-documents.preview');

            Route::get('branch-documents/{document}/download', [BranchDocumentController::class, 'download'])
                ->name('branch-documents.download');

            Route::post('branches/{branch}/agreements', [BranchAgreementController::class, 'store'])
                ->name('branch-agreements.store');

            Route::get('branch-agreements/{agreement}/preview', [BranchAgreementController::class, 'preview'])
                ->name('branch-agreements.preview');

            Route::get('branch-agreements/{agreement}/download', [BranchAgreementController::class, 'download'])
                ->name('branch-agreements.download');
        });
    });
