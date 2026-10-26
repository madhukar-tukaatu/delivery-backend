<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Transfers page (/admin/transfers) needs transfers.view, plus
 * transfers.dispatch / transfers.receive / transfers.receive_transit for its
 * actions. RoleSeeder never granted them, so a database seeded from code
 * (local / fresh environments) showed no Transfers menu and every transfers
 * endpoint answered 403 for branch managers.
 *
 * Insert-only and idempotent: grants missing rows, never removes anything.
 * Roles mirror the dispatches.* grants: roles that may dispatch get the full
 * set, roles that may only receive get view + receive.
 */
return new class extends Migration
{
    private const GRANTS = [
        'main_admin' => ['transfers.view', 'transfers.dispatch', 'transfers.receive', 'transfers.receive_transit', 'transfers.repair'],
        'branch_manager' => ['transfers.view', 'transfers.dispatch', 'transfers.receive', 'transfers.receive_transit'],
        'sub_branch_manager' => ['transfers.view', 'transfers.receive', 'transfers.receive_transit'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_has_permissions')) {
            return;
        }
        $guard = DB::table('permissions')->where('name', 'like', 'transfers.%')->value('guard_name') ?: 'web';

        foreach (self::GRANTS as $roleName => $permissions) {
            $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', $guard)->value('id');
            if (! $roleId) {
                continue;
            }
            foreach ($permissions as $name) {
                $permId = DB::table('permissions')->where('name', $name)->where('guard_name', $guard)->value('id');
                if (! $permId) {
                    $permId = DB::table('permissions')->insertGetId([
                        'name' => $name, 'guard_name' => $guard, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                if (! DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $permId)->exists()) {
                    DB::table('role_has_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permId]);
                }
            }
        }

        try {
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        } catch (\Throwable) {
            // cache not available: permissions refresh on next cache expiry
        }
    }

    public function down(): void
    {
        // Grants may have existed before this migration (set in the Roles
        // screen); removing them could lock branches out. Nothing to undo.
    }
};
