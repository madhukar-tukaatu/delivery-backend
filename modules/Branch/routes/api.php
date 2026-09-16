<?php

use Illuminate\Support\Facades\Route;

use Modules\Branch\Http\Controllers\AdminCoverageLocationController;
use Modules\Branch\Http\Controllers\BranchAgreementController;
use Modules\Branch\Http\Controllers\BranchController;
use Modules\Branch\Http\Controllers\BranchDocumentController;
use Modules\Branch\Http\Controllers\BranchTeamController;

Route::prefix('v1/admin')
    ->name('admin.')
    ->middleware(['auth:sanctum'])
    ->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Branch Parent Options
        |--------------------------------------------------------------------------
        */

        Route::get(
            'branches/parent-options',
            [BranchController::class, 'parentOptions']
        )->name('branches.parent-options');

        /*
        |--------------------------------------------------------------------------
        | Coverage Location Map
        |--------------------------------------------------------------------------
        */

        Route::get(
            'coverage-locations/map',
            [AdminCoverageLocationController::class, 'map']
        )->name('coverage-locations.map');

        /*
        |--------------------------------------------------------------------------
        | Coverage Location Parent Options
        |--------------------------------------------------------------------------
        |
        | Used by both create and edit Sub-Branch forms.
        |
        | Example:
        |
        | GET /api/v1/admin/coverage-locations/parent-options
        |
        | Edit:
        |
        | GET /api/v1/admin/coverage-locations/parent-options?exclude_id=7
        |
        */

        Route::get(
            'coverage-locations/parent-options',
            [AdminCoverageLocationController::class, 'parentOptions']
        )->name('coverage-locations.parent-options');

        /*
        |--------------------------------------------------------------------------
        | Coverage Location Conversion Options
        |--------------------------------------------------------------------------
        */

        Route::get(
            'coverage-locations/{coverageLocation}/conversion-options',
            [
                AdminCoverageLocationController::class,
                'conversionOptions',
            ]
        )->name('coverage-locations.conversion-options');

        /*
        |--------------------------------------------------------------------------
        | Main → Sub Branch Conversion
        |--------------------------------------------------------------------------
        */

        Route::post(
            'coverage-locations/{coverageLocation}/convert-to-sub-branch',
            [
                AdminCoverageLocationController::class,
                'convertToSubBranch',
            ]
        )->name('coverage-locations.convert-to-sub-branch');

        /*
        |--------------------------------------------------------------------------
        | Coverage Location CRUD
        |--------------------------------------------------------------------------
        */

        Route::apiResource(
            'coverage-locations',
            AdminCoverageLocationController::class
        );

        /*
        |--------------------------------------------------------------------------
        | Branch Management
        |--------------------------------------------------------------------------
        */

        Route::middleware(['route.permission'])->group(function () {

            Route::apiResource(
                'branches',
                BranchController::class
            )->names([
                'index' => 'branches.index',
                'store' => 'branches.store',
                'show' => 'branches.show',
                'update' => 'branches.update',
                'destroy' => 'branches.destroy',
            ]);

            /*
            |--------------------------------------------------------------------------
            | Branch Actions
            |--------------------------------------------------------------------------
            */

            Route::post(
                'branches/{branch}/resend-account-invitation',
                [
                    BranchController::class,
                    'resendAccountInvitation',
                ]
            )->name('branches.resend-account-invitation');

            Route::post(
                'branches/{branch}/approve',
                [
                    BranchController::class,
                    'approve',
                ]
            )->name('branches.approve');

            Route::post(
                'branches/{branch}/reject',
                [
                    BranchController::class,
                    'reject',
                ]
            )->name('branches.reject');

            Route::post(
                'branches/{branch}/suspend',
                [
                    BranchController::class,
                    'suspend',
                ]
            )->name('branches.suspend');

            Route::post(
                'branches/{branch}/activate',
                [
                    BranchController::class,
                    'activate',
                ]
            )->name('branches.activate');

            /*
            |--------------------------------------------------------------------------
            | Branch Documents
            |--------------------------------------------------------------------------
            */

            Route::post(
                'branches/{branch}/documents',
                [
                    BranchDocumentController::class,
                    'store',
                ]
            )->name('branch-documents.store');

            Route::put(
                'branch-documents/{document}',
                [
                    BranchDocumentController::class,
                    'update',
                ]
            )->name('branch-documents.update');

            Route::delete(
                'branch-documents/{document}',
                [
                    BranchDocumentController::class,
                    'destroy',
                ]
            )->name('branch-documents.destroy');

            Route::patch(
                'branch-documents/{document}/verify',
                [
                    BranchDocumentController::class,
                    'verify',
                ]
            )->name('branch-documents.verify');

            Route::get(
                'branch-documents/{document}/preview',
                [
                    BranchDocumentController::class,
                    'preview',
                ]
            )->name('branch-documents.preview');

            Route::get(
                'branch-documents/{document}/download',
                [
                    BranchDocumentController::class,
                    'download',
                ]
            )->name('branch-documents.download');

            /*
            |--------------------------------------------------------------------------
            | Branch Agreements
            |--------------------------------------------------------------------------
            */

            Route::post(
                'branches/{branch}/agreements',
                [
                    BranchAgreementController::class,
                    'store',
                ]
            )->name('branch-agreements.store');

            Route::get(
                'branch-agreements/{agreement}/preview',
                [
                    BranchAgreementController::class,
                    'preview',
                ]
            )->name('branch-agreements.preview');

            Route::get(
                'branch-agreements/{agreement}/download',
                [
                    BranchAgreementController::class,
                    'download',
                ]
            )->name('branch-agreements.download');
        });
    });

/*
|--------------------------------------------------------------------------
| Branch Team
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')
    ->prefix('branches/{branch}/team')
    ->group(function () {

        Route::get(
            '/',
            [BranchTeamController::class, 'index']
        );

        Route::post(
            '/reveal-credentials',
            [BranchTeamController::class, 'revealCredentials']
        );

        Route::put(
            '/positions/{position}/assign',
            [BranchTeamController::class, 'assign']
        );

        Route::put(
            '/positions/{position}/unassign',
            [BranchTeamController::class, 'unassign']
        );

        Route::post(
            '/positions/{position}/reset-credentials',
            [BranchTeamController::class, 'resetCredentials']
        );
    });