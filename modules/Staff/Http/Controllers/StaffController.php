<?php

declare(strict_types=1);

namespace Modules\Staff\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Staff\Models\Staff;
use Modules\Staff\Services\StaffService;

final class StaffController extends Controller
{
    public function __construct(
        private readonly StaffService $staffService,
    ) {
    }

    public function index(
        Request $request
    ): JsonResponse {
        $staff = $this->staffService
            ->paginateForUser(
                $request->user(),
                $request->integer(
                    'per_page',
                    20
                ),
                $request->input('q')
            );

        return response()->json([
            'success' => true,
            'data' => $staff,
        ]);
    }


    public function show(
        Request $request,
        Staff $staff
    ): JsonResponse {
        $this->staffService
            ->authorizeAccess(
                $request->user(),
                $staff
            );

        return response()->json([
            'success' => true,
            'data' => $staff->load([
                'user',
                'role',
                'branch',
            ]),
        ]);
    }


    public function store(
        Request $request
    ): JsonResponse {
        $staff =
            $this->staffService
                ->createForUser(
                    $request->user(),
                    $request->all()
                );

        return response()->json([
            'success' => true,
            'message' =>
                'Staff created successfully.',
            'data' => $staff,
        ], 201);
    }


    public function update(
        Request $request,
        Staff $staff
    ): JsonResponse {
        $this->staffService
            ->authorizeAccess(
                $request->user(),
                $staff
            );

        $updated =
            $this->staffService
                ->update(
                    $staff,
                    $request->all()
                );

        return response()->json([
            'success' => true,
            'message' =>
                'Staff updated successfully.',
            'data' => $updated,
        ]);
    }


    public function destroy(
        Request $request,
        Staff $staff
    ): JsonResponse {
        $this->staffService
            ->authorizeAccess(
                $request->user(),
                $staff
            );

        $this->staffService
            ->deactivate($staff);

        return response()->json([
            'success' => true,
            'message' =>
                'Staff deactivated successfully.',
        ]);
    }


    public function toggle(
        Request $request,
        Staff $staff
    ): JsonResponse {
        $this->staffService
            ->authorizeAccess(
                $request->user(),
                $staff
            );

        $updated =
            $this->staffService
                ->toggle($staff);

        return response()->json([
            'success' => true,
            'message' =>
                'Staff status updated.',
            'data' => $updated,
        ]);
    }
}