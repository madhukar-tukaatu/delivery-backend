<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Modules\Access\Models\MenuItem;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->orWhere('phone', $data['email'])->first();

        // dd($user);
        if (!$user || !Hash::check($data['password'], $user->password) || !$user->is_active) {
            return ApiResponse::error('Invalid login credentials.', 422);
        }

        $token = $user->createToken('dashboard')->plainTextToken;
        return ApiResponse::success([
            'token' => $token,
            'user' => $this->presentUser($user),
        ], 'Logged in successfully.');
    }

    public function me(Request $request)
    {
        return ApiResponse::success($this->presentUser($request->user()));
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();
        return ApiResponse::success(null, 'Logged out successfully.');
    }

    private function presentUser(User $user): array
    {
        $user->load(['branch', 'merchant']);
        $roles = method_exists($user, 'roleNames') ? $user->roleNames() : array_filter([$user->role]);
        $permissions = method_exists($user, 'permissionNames') ? $user->permissionNames() : [];
        $section = $user->merchant_id || in_array('merchant', $roles, true) ? 'merchant' : (in_array($user->role, ['rider', 'pickup_staff'], true) ? 'staff' : 'admin');

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
            'is_active' => $user->is_active,
            'is_super_admin' => method_exists($user, 'isSuperAdmin') ? $user->isSuperAdmin() : $user->role === 'super_admin',
            'section' => $section,
            'menus' => MenuItem::visibleFor($user, $section),
        ];
    }
}
