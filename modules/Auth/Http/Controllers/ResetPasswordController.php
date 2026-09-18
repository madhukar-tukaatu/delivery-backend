<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

final class ResetPasswordController extends Controller
{
    public function __invoke(
        Request $request
    ): JsonResponse {
        $request->validate([
            'token' => [
                'required',
                'string',
            ],

            'email' => [
                'required',
                'email',
            ],

            'password' => [
                'required',
                'string',
                'confirmed',
                PasswordRule::min(8)
                    ->letters()
                    ->mixedCase()
                    ->numbers(),
            ],

            'password_confirmation' => [
                'required',
                'string',
            ],

            'logout_all_devices' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $status = Password::broker()->reset(
            [
                'email' => $request->email,
                'password' => $request->password,
                'password_confirmation' => $request->password_confirmation,
                'token' => $request->token,
            ],

            function (User $user, string $password) use ($request): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'email_verified_at' => $user->email_verified_at ?: now(),
                ])->save();

                // Handle device logout based on user preference
                if ($request->boolean('logout_all_devices')) {
                    // Delete all existing tokens (logout from all devices)
                    $user->tokens()->delete();
                } else {
                    // Keep current session, delete others
                    $currentToken = $request->bearerToken();
                    if ($currentToken) {
                        $user->tokens()
                            ->where('token', '!=', hash('sha256', $currentToken))
                            ->delete();
                    } else {
                        // No current token, delete all
                        $user->tokens()->delete();
                    }
                }

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        // Get the user for new token generation
        $user = User::where('email', $request->email)->first();
        
        if (!$user) {
            return ApiResponse::error(
                'User not found after password reset.',
                404
            );
        }

        // Create new token for the user
        $newToken = $user->createToken('dashboard')->plainTextToken;

        return ApiResponse::success(
            [
                'token' => $newToken,
                'user' => $this->presentUser($user),
                'message' => 'Password reset successfully.',
            ],
            'Your password has been reset successfully.'
        );
    }

    private function presentUser(User $user): array
    {
        $user->load([
            'branch',
            'merchant',
        ]);

        $roles = method_exists($user, 'roleNames')
            ? $user->roleNames()
            : array_filter([$user->role]);

        $roles = collect($roles)
            ->filter()
            ->map(static fn ($role): string =>
                is_string($role)
                    ? $role
                    : (string) ($role->name ?? $role->key ?? '')
            )
            ->filter()
            ->values()
            ->all();

        $permissions = method_exists($user, 'permissionNames')
            ? $user->permissionNames()
            : [];

        $permissions = collect($permissions)
            ->filter()
            ->map(static fn ($permission): string =>
                is_string($permission)
                    ? $permission
                    : (string) ($permission->name ?? $permission->key ?? '')
            )
            ->filter()
            ->unique()
            ->values()
            ->all();

        $isMerchant = (bool) $user->merchant_id || in_array('merchant', $roles, true);
        $isStaff = count(array_intersect(['rider', 'pickup_staff'], $roles)) > 0;
        $section = $isMerchant ? 'merchant' : ($isStaff ? 'staff' : 'admin');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'roles' => $roles,
            'permissions' => $permissions,
            'branch' => $user->branch,
            'merchant' => $user->merchant,
            'is_active' => (bool) $user->is_active,
            'email_verified_at' => $user->email_verified_at,
            'last_login_at' => $user->last_login_at,
            'section' => $section,
        ];
    }
}
