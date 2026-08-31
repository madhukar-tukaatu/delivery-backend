<?php

namespace Modules\Access\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    /**
     * List all roles with permissions.
     */
    public function index(Request $request)
    {
        $roles = Role::query()
            ->with([
                'permissions:id,name,group,label,description',
            ])
            ->orderBy('name')
            ->get();

        return ApiResponse::success($roles);
    }

    /**
     * Create role.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z0-9_]+$/',
                'unique:roles,name',
            ],

            'label' => [
                'nullable',
                'string',
                'max:150',
            ],

            'description' => [
                'nullable',
                'string',
                'max:500',
            ],

            'permissions' => [
                'nullable',
                'array',
            ],

            'permissions.*' => [
                'string',
                'exists:permissions,name',
            ],
        ]);

        $role = Role::create([
            'name' => $data['name'],
            'guard_name' => 'web',
            'label' => $data['label']
                ?? ucwords(str_replace('_', ' ', $data['name'])),
            'description' => $data['description'] ?? null,
            'is_system' => false,
        ]);

        $role->syncPermissions(
            $data['permissions'] ?? []
        );

        return ApiResponse::success(
            $role->load(
                'permissions:id,name,group,label,description'
            ),
            'Role created.',
            201
        );
    }

    /**
     * Show one role.
     */
    public function show(Role $role)
    {
        return ApiResponse::success(
            $role->load(
                'permissions:id,name,group,label,description'
            )
        );
    }

    /**
     * Update role and its permissions.
     */
    public function update(
        Request $request,
        Role $role
    ) {
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z0-9_]+$/',
                'unique:roles,name,' . $role->id,
            ],

            'label' => [
                'nullable',
                'string',
                'max:150',
            ],

            'description' => [
                'nullable',
                'string',
                'max:500',
            ],

            'permissions' => [
                'nullable',
                'array',
            ],

            'permissions.*' => [
                'string',
                'exists:permissions,name',
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Protect super admin
        |--------------------------------------------------------------------------
        */

        if ($role->name === 'super_admin') {
            if ($data['name'] !== 'super_admin') {
                return ApiResponse::error(
                    'The super_admin role name cannot be changed.',
                    422
                );
            }
        }

        $role->update([
            'name' => $data['name'],
            'label' => $data['label']
                ?? ucwords(str_replace('_', ' ', $data['name'])),
            'description' => $data['description'] ?? null,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Replace role permissions
        |--------------------------------------------------------------------------
        */

        if ($role->name !== 'super_admin') {
            $role->syncPermissions(
                $data['permissions'] ?? []
            );
        }

        return ApiResponse::success(
            $role->load(
                'permissions:id,name,group,label,description'
            ),
            'Role updated.'
        );
    }

    /**
     * Delete role.
     */
    public function destroy(Role $role)
    {
        if (
            $role->is_system
            || $role->name === 'super_admin'
        ) {
            return ApiResponse::error(
                'System roles cannot be deleted.',
                422
            );
        }

        $role->delete();

        return ApiResponse::success(
            null,
            'Role deleted.'
        );
    }

    /**
     * Permission catalogue.
     *
     * This is NOT the same thing as assigning permissions.
     *
     * It returns every permission generated by the backend and groups
     * them for the RoleForm/PermissionSelector.
     */
    public function permissions()
    {
        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('group')
            ->orderBy('name')
            ->get()
            ->groupBy(function (Permission $permission) {
                return $permission->group
                    ?: explode('.', $permission->name)[0];
            })
            ->map(function ($items, $group) {
                return [
                    'group_key' => $group,

                    'group_label' => ucwords(
                        str_replace(
                            ['_', '-'],
                            ' ',
                            $group
                        )
                    ),

                    'permissions' => $items
                        ->map(function (Permission $permission) {
                            return [
                                'id' => $permission->id,
                                'name' => $permission->name,

                                'label' => $permission->label
                                    ?: ucwords(
                                        str_replace(
                                            ['.', '_', '-'],
                                            ' ',
                                            $permission->name
                                        )
                                    ),

                                'description' =>
                                    $permission->description,
                            ];
                        })
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        return ApiResponse::success(
            $permissions
        );
    }
}