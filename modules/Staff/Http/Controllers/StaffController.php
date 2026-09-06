<?php

declare(strict_types=1);

namespace Modules\Staff\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Staff\Services\StaffService;
use Spatie\Permission\Models\Role;

final class StaffController extends Controller
{
    public function __construct(
        private readonly StaffService $staffService,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | INDEX
    |--------------------------------------------------------------------------
    |
    | GET /api/v1/admin/staff
    |
    | For superadmin: returns staff grouped by branch
    | For branch manager: returns only their branch staff
    |
    */

    public function index(
        Request $request
    ): JsonResponse {
        $user = $request->user();

        if ($user === null) {
            abort(
                401,
                'Unauthenticated.'
            );
        }

        $perPage = min(
            max(
                (int) $request->input(
                    'per_page',
                    20
                ),
                1
            ),
            100
        );

        $page = max(
            (int) $request->input(
                'page',
                1
            ),
            1
        );

        $search = trim(
            (string) $request->input(
                'q',
                $request->input(
                    'search',
                    ''
                )
            )
        );

        $role = trim(
            (string) $request->input(
                'role',
                ''
            )
        );

        $isSuperAdmin = $user->hasAnyRole([
            'super_admin',
            'admin',
        ]);

        $query = $this->staffService
            ->queryForUser($user);

        /*
        |--------------------------------------------------------------------------
        | Search
        |--------------------------------------------------------------------------
        */

        if ($search !== '') {
            $query->where(function (
                Builder $q
            ) use ($search): void {

                $q->where(
                    'name',
                    'like',
                    '%' . $search . '%'
                )
                    ->orWhere(
                        'email',
                        'like',
                        '%' . $search . '%'
                    )
                    ->orWhere(
                        'phone',
                        'like',
                        '%' . $search . '%'
                    );
            });
        }

        /*
        |--------------------------------------------------------------------------
        | Role filter
        |--------------------------------------------------------------------------
        */

        if ($role !== '') {
            $query->whereHas(
                'roles',
                function (
                    Builder $roleQuery
                ) use ($role): void {
                    $roleQuery->where(
                        'name',
                        $role
                    );
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $staff = $query->paginate(
            $perPage,
            ['*'],
            'page',
            $page
        );

        /*
        |--------------------------------------------------------------------------
        | Format response for superadmin (group by branch)
        |--------------------------------------------------------------------------
        */

        if ($isSuperAdmin) {
            $grouped = $this->groupStaffByBranch($staff);

            return ApiResponse::success(
                $grouped,
                'All staff retrieved successfully (grouped by branch).'
            );
        }

        return ApiResponse::success(
            $staff,
            'Branch staff retrieved successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | GROUP STAFF BY BRANCH
    |--------------------------------------------------------------------------
    |
    | Helper method to group paginated staff results by branch for superadmin view
    |
    */

    private function groupStaffByBranch($paginatedStaff): array
    {
        $grouped = [];

        foreach ($paginatedStaff->items() as $staffMember) {
            $branchId = $staffMember->branch_id ?? 'unassigned';
            $branchName = $staffMember->branch?->name ?? 'Unassigned Branch';

            if (!isset($grouped[$branchId])) {
                $grouped[$branchId] = [
                    'branch_id' => $branchId,
                    'branch_name' => $branchName,
                    'staff' => [],
                ];
            }

            $grouped[$branchId]['staff'][] = $staffMember;
        }

        // Convert to indexed array and sort by branch name
        $result = array_values($grouped);
        usort($result, function ($a, $b) {
            return strcmp($a['branch_name'], $b['branch_name']);
        });

        return [
            'data' => $result,
            'pagination' => [
                'current_page' => $paginatedStaff->currentPage(),
                'total' => $paginatedStaff->total(),
                'per_page' => $paginatedStaff->perPage(),
                'last_page' => $paginatedStaff->lastPage(),
                'from' => $paginatedStaff->firstItem(),
                'to' => $paginatedStaff->lastItem(),
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | SHOW
    |--------------------------------------------------------------------------
    */

    public function show(
        Request $request,
        int $staff
    ): JsonResponse {
        $user = $request->user();

        $staffUser = $this->staffService
            ->findForUser(
                $user,
                $staff
            );

        if ($staffUser === null) {
            abort(
                404,
                'Staff member not found.'
            );
        }

        return ApiResponse::success(
            $staffUser,
            'Staff member retrieved successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | AVAILABLE STAFF ROLES
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | This does NOT use the generic roles endpoint.
    |
    | Therefore branch_manager does NOT need:
    |
    | roles.view
    |
    */

    public function roles(
        Request $request
    ): JsonResponse {
        $user = $request->user();

        if ($user === null) {
            abort(
                401,
                'Unauthenticated.'
            );
        }

        $roles = $this->staffService
            ->availableRolesForUser(
                $user
            );

        return ApiResponse::success(
            $roles,
            'Staff roles retrieved successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE
    |--------------------------------------------------------------------------
    */

    public function store(
        Request $request
    ): JsonResponse {
        $user = $request->user();

        if ($user === null) {
            abort(
                401,
                'Unauthenticated.'
            );
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],

            'phone' => [
                'nullable',
                'string',
                'max:30',
            ],

            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],

            'role' => [
                'required',
                'string',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $staff = DB::transaction(
            function () use (
                $validated,
                $user
            ): User {

                return $this->staffService
                    ->createForUser(
                        $user,
                        $validated
                    );
            }
        );

        return ApiResponse::success(
            $staff,
            'Staff member created successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    public function update(
        Request $request,
        int $staff
    ): JsonResponse {
        $user = $request->user();

        if ($user === null) {
            abort(
                401,
                'Unauthenticated.'
            );
        }

        $staffUser = $this->staffService
            ->findForUser(
                $user,
                $staff
            );

        if ($staffUser === null) {
            abort(
                404,
                'Staff member not found.'
            );
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique(
                    'users',
                    'email'
                )->ignore(
                    $staffUser->id
                ),
            ],

            'phone' => [
                'nullable',
                'string',
                'max:30',
            ],

            'password' => [
                'nullable',
                'string',
                'min:8',
                'confirmed',
            ],

            'role' => [
                'required',
                'string',
            ],

            'is_active' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $updated = DB::transaction(
            function () use (
                $user,
                $staffUser,
                $validated
            ): User {

                return $this->staffService
                    ->updateForUser(
                        $user,
                        $staffUser,
                        $validated
                    );
            }
        );

        return ApiResponse::success(
            $updated,
            'Staff member updated successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | TOGGLE
    |--------------------------------------------------------------------------
    */

    public function toggle(
        Request $request,
        int $staff
    ): JsonResponse {
        $user = $request->user();

        if ($user === null) {
            abort(
                401,
                'Unauthenticated.'
            );
        }

        $staffUser = $this->staffService
            ->findForUser(
                $user,
                $staff
            );

        if ($staffUser === null) {
            abort(
                404,
                'Staff member not found.'
            );
        }

        $updated = DB::transaction(
            function () use (
                $user,
                $staffUser
            ): User {

                return $this->staffService
                    ->toggleForUser(
                        $user,
                        $staffUser
                    );
            }
        );

        return ApiResponse::success(
            $updated,
            'Staff status updated successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE / DEACTIVATE
    |--------------------------------------------------------------------------
    */

    public function destroy(
        Request $request,
        int $staff
    ): JsonResponse {
        $user = $request->user();

        if ($user === null) {
            abort(
                401,
                'Unauthenticated.'
            );
        }

        $staffUser = $this->staffService
            ->findForUser(
                $user,
                $staff
            );

        if ($staffUser === null) {
            abort(
                404,
                'Staff member not found.'
            );
        }

        DB::transaction(
            function () use (
                $user,
                $staffUser
            ): void {

                $this->staffService
                    ->deactivateForUser(
                        $user,
                        $staffUser
                    );
            }
        );

        return ApiResponse::success(
            null,
            'Staff member deactivated successfully.'
        );
    }
}