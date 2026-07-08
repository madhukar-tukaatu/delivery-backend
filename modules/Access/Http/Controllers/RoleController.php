<?php

namespace Modules\Access\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(Request $request)
    {
        $roles = Role::query()->with('permissions:id,name,group')->orderBy('name')->get();
        return ApiResponse::success($roles);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:roles,name'],
            'label' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $role = Role::create([
            'name' => $data['name'],
            'guard_name' => 'web',
            'label' => $data['label'] ?? ucwords(str_replace('_', ' ', $data['name'])),
            'description' => $data['description'] ?? null,
            'is_system' => false,
        ]);
        $role->syncPermissions($data['permissions'] ?? []);

        return ApiResponse::success($role->load('permissions:id,name,group'), 'Role created.', 201);
    }

    public function show(Role $role)
    {
        return ApiResponse::success($role->load('permissions:id,name,group'));
    }

    public function update(Request $request, Role $role)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:roles,name,' . $role->id],
            'label' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        if ($role->name === 'super_admin' && $data['name'] !== 'super_admin') {
            return ApiResponse::error('The super_admin role name cannot be changed.', 422);
        }

        $role->update([
            'name' => $data['name'],
            'label' => $data['label'] ?? ucwords(str_replace('_', ' ', $data['name'])),
            'description' => $data['description'] ?? null,
        ]);
        $role->syncPermissions($data['permissions'] ?? []);

        return ApiResponse::success($role->load('permissions:id,name,group'), 'Role updated.');
    }

    public function destroy(Role $role)
    {
        if ($role->is_system || $role->name === 'super_admin') {
            return ApiResponse::error('System roles cannot be deleted.', 422);
        }
        $role->delete();
        return ApiResponse::success(null, 'Role deleted.');
    }

    // public function permissions()
    // {
    //     $permissions = Permission::query()->orderBy('group')->orderBy('name')->get()->groupBy('group');
    //     return ApiResponse::success($permissions);
    // }

    public function permissions()
    {
        $permissions = \Spatie\Permission\Models\Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('group')
            ->orderBy('name')
            ->get()
            ->groupBy(function ($permission) {
                return $permission->group ?? explode('.', $permission->name)[0];
            })
            ->map(function ($items, $group) {
                return [
                    'group_key' => $group,
                    'group_label' => ucwords(str_replace('_', ' ', $group)),
                    'permissions' => $items->map(function ($permission) {
                        return [
                            'id' => $permission->id,
                            'name' => $permission->name,
                            'label' => $permission->label
                                ?? ucwords(str_replace(['.', '_'], ' ', $permission->name)),
                            'description' => $permission->description,
                        ];
                    })->values(),
                ];
            })
            ->values();

        return response()->json([
            'data' => $permissions,
        ]);
    }
}
