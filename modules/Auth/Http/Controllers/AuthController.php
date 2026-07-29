<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Modules\Access\Models\MenuItem;

class AuthController extends Controller
{
    public function login(
        Request $request
    ): JsonResponse {
        $data = $request->validate([
            'email' => [
                'required',
                'string',
            ],

            'password' => [
                'required',
                'string',
            ],
        ]);

        $identity = trim(
            (string) $data['email']
        );

        $user = User::query()
            ->where(
                'email',
                $identity
            )
            ->orWhere(
                'phone',
                $identity
            )
            ->first();

        if (
            !$user ||
            !Hash::check(
                $data['password'],
                $user->password
            ) ||
            !$user->is_active
        ) {
            return ApiResponse::error(
                'Invalid login credentials.',
                422
            );
        }

        /*
         * Record the successful login before returning
         * the authenticated user response.
         */
        $user->forceFill([
            'last_login_at' => now(),
        ])->saveQuietly();

        $token = $user
            ->createToken('dashboard')
            ->plainTextToken;

        return ApiResponse::success([
            'token' => $token,

            'user' =>
                $this->presentUser(
                    $user->fresh()
                ),
        ], 'Logged in successfully.');
    }

    public function me(
        Request $request
    ): JsonResponse {
        return ApiResponse::success(
            $this->presentUser(
                $request->user()
            )
        );
    }

    public function logout(
        Request $request
    ): JsonResponse {
        $request
            ->user()
            ?->currentAccessToken()
            ?->delete();

        return ApiResponse::success(
            null,
            'Logged out successfully.'
        );
    }

    private function presentUser(
        User $user
    ): array {
        $user->load([
            'branch',
            'merchant',
        ]);

        $roles = method_exists(
            $user,
            'roleNames'
        )
            ? $user->roleNames()
            : array_filter([
                $user->role,
            ]);

        /*
         * Normalize collections and arrays so that
         * in_array() always receives an array.
         */
        $roles = collect($roles)
            ->filter()
            ->map(
                static fn ($role): string =>
                    is_string($role)
                        ? $role
                        : (string) (
                            $role->name
                            ?? $role->key
                            ?? ''
                        )
            )
            ->filter()
            ->values()
            ->all();

        $permissions = method_exists(
            $user,
            'permissionNames'
        )
            ? $user->permissionNames()
            : [];

        $permissions = collect(
            $permissions
        )
            ->filter()
            ->map(
                static fn ($permission): string =>
                    is_string($permission)
                        ? $permission
                        : (string) (
                            $permission->name
                            ?? $permission->key
                            ?? ''
                        )
            )
            ->filter()
            ->unique()
            ->values()
            ->all();

        $isMerchant =
            (bool) $user->merchant_id ||
            in_array(
                'merchant',
                $roles,
                true
            );

        $isStaff = count(
            array_intersect(
                [
                    'rider',
                    'pickup_staff',
                ],
                $roles
            )
        ) > 0;

        $section = $isMerchant
            ? 'merchant'
            : (
                $isStaff
                    ? 'staff'
                    : 'admin'
            );

        return [
            'id' =>
                $user->id,

            'name' =>
                $user->name,

            'email' =>
                $user->email,

            'phone' =>
                $user->phone,

            'role' =>
                $user->role,

            'roles' =>
                $roles,

            'permissions' =>
                $permissions,

            'branch' =>
                $user->branch,

            'merchant' =>
                $user->merchant,

            'is_active' =>
                (bool) $user->is_active,

            'email_verified_at' =>
                $user->email_verified_at,

            'account_setup_completed_at' =>
                $user
                    ->account_setup_completed_at,

            'last_login_at' =>
                $user->last_login_at,

            'is_super_admin' =>
                method_exists(
                    $user,
                    'isSuperAdmin'
                )
                    ? $user->isSuperAdmin()
                    : in_array(
                        'super_admin',
                        $roles,
                        true
                    ),

            'section' =>
                $section,

            /*
             * Your existing role-wise menu system remains
             * responsible for the visible dashboard menus.
             */
            'menus' =>
                MenuItem::visibleFor(
                    $user,
                    $section
                ),
        ];
    }
}