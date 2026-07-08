<?php

namespace Modules\Access\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query()->with(['branch', 'merchant', 'roles:id,name,label'])->latest();
        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(function ($x) use ($q) {
                $x->where('name', 'like', "%$q%")
                    ->orWhere('email', 'like', "%$q%")
                    ->orWhere('phone', 'like', "%$q%");
            });
        }
        if ($request->filled('role')) {
            $query->role($request->role);
        }
        return ApiResponse::success($query->paginate((int) $request->get('per_page', 20)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['nullable', 'string', 'min:6'],
            'role' => ['required', 'string', 'exists:roles,name'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'merchant_id' => ['nullable', 'exists:merchants,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'role' => $data['role'],
            'branch_id' => $data['branch_id'] ?? null,
            'merchant_id' => $data['merchant_id'] ?? null,
            'password' => Hash::make($data['password'] ?? 'password'),
            'is_active' => $data['is_active'] ?? true,
        ]);
        $user->syncRoles([$data['role']]);

        return ApiResponse::success($user->load(['branch', 'merchant', 'roles:id,name,label']), 'User created.', 201);
    }

    public function show(User $user)
    {
        return ApiResponse::success($user->load(['branch', 'merchant', 'roles:id,name,label', 'permissions:id,name,group']));
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'unique:users,email,'.$user->id],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['nullable', 'string', 'min:6'],
            'role' => ['required', 'string', 'exists:roles,name'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'merchant_id' => ['nullable', 'exists:merchants,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $payload = [
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'role' => $data['role'],
            'branch_id' => $data['branch_id'] ?? null,
            'merchant_id' => $data['merchant_id'] ?? null,
            'is_active' => $data['is_active'] ?? true,
        ];
        if (!empty($data['password'])) {
            $payload['password'] = Hash::make($data['password']);
        }

        $user->update($payload);
        $user->syncRoles([$data['role']]);

        return ApiResponse::success($user->load(['branch', 'merchant', 'roles:id,name,label']), 'User updated.');
    }

    public function destroy(User $user)
    {
        if ($user->isSuperAdmin()) {
            return ApiResponse::error('Super admin cannot be deleted.', 422);
        }
        $user->delete();
        return ApiResponse::success(null, 'User deleted.');
    }

    public function toggle(User $user)
    {
        if ($user->isSuperAdmin()) {
            return ApiResponse::error('Super admin cannot be disabled.', 422);
        }
        $user->update(['is_active' => !$user->is_active]);
        return ApiResponse::success($user, 'User status updated.');
    }
}
