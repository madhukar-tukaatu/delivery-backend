<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * transfers.repair: admin "Repair receive" on a TR (clear a wrong seal flag,
 * receive + sort missing parcels). Granted to main_admin; super_admin always passes.
 * Guarded: does nothing when the permission tables are not there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }
        $guard = DB::table('permissions')->where('name', 'like', 'transfers.%')->value('guard_name') ?: 'web';
        $id = DB::table('permissions')->where('name', 'transfers.repair')->where('guard_name', $guard)->value('id');
        if (! $id) {
            $id = DB::table('permissions')->insertGetId(['name' => 'transfers.repair', 'guard_name' => $guard, 'created_at' => now(), 'updated_at' => now()]);
        }
        if (Schema::hasTable('roles') && Schema::hasTable('role_has_permissions')) {
            $role = DB::table('roles')->where('name', 'main_admin')->where('guard_name', $guard)->value('id');
            if ($role && ! DB::table('role_has_permissions')->where('role_id', $role)->where('permission_id', $id)->exists()) {
                DB::table('role_has_permissions')->insert(['role_id' => $role, 'permission_id' => $id]);
            }
        }
        $this->forgetPermissionCache();
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }
        $ids = DB::table('permissions')->where('name', 'transfers.repair')->pluck('id');
        if (Schema::hasTable('role_has_permissions')) {
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        }
        if (Schema::hasTable('model_has_permissions')) {
            DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        }
        DB::table('permissions')->whereIn('id', $ids)->delete();
        $this->forgetPermissionCache();
    }

    private function forgetPermissionCache(): void
    {
        try {
            if (class_exists(\Spatie\Permission\PermissionRegistrar::class)) {
                app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
            }
        } catch (\Throwable) {
            // cache not available: permissions refresh on next cache expiry
        }
    }
};
