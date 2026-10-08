<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Access\Enums\SystemRole;
use Spatie\Permission\Guard;
use Spatie\Permission\PermissionRegistrar;

/**
 * Branch staff creation failed with
 *   RoleDoesNotExist: There is no role named `staff` for guard `web`.
 *
 * Older databases have users.role DEFAULT 'staff' (the migration now says
 * 'branch_staff') and the User model syncs Spatie roles from that column on
 * save. This migration (idempotent, safe to re-run):
 *   1. creates every SystemRole for the users' guard if missing (no permissions
 *      are granted; existing roles are untouched),
 *   2. changes the users.role default to 'branch_staff',
 *   3. for users whose users.role is not an existing role and who have exactly
 *      one Spatie role, copies that role name into users.role (no permission
 *      change). Users without a Spatie role are only reported in the log.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roleClass = app(PermissionRegistrar::class)->getRoleClass();
        $rolesTable = config('permission.table_names.roles', 'roles');
        $modelHasRoles = config('permission.table_names.model_has_roles', 'model_has_roles');
        $modelKey = config('permission.column_names.model_morph_key', 'model_id');

        if (! Schema::hasTable($rolesTable) || ! Schema::hasTable('users')) {
            return;
        }

        $guard = Guard::getDefaultName(User::class);

        foreach (SystemRole::values() as $name) {
            $roleClass::findOrCreate($name, $guard);
        }

        if (! Schema::hasColumn('users', 'role')) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('role')->default(SystemRole::BranchStaff->value)->change();
        });

        $validRoles = DB::table($rolesTable)->where('guard_name', $guard)->pluck('name')->all();
        $morphType = (new User())->getMorphClass();
        $fixed = 0;
        $noRole = [];

        DB::table('users')
            ->select(['id', 'role'])
            ->where(function ($q) use ($validRoles) {
                $q->whereNull('role')->orWhereNotIn('role', $validRoles);
            })
            ->orderBy('id')
            ->chunkById(500, function ($users) use ($rolesTable, $modelHasRoles, $modelKey, $morphType, $guard, &$fixed, &$noRole) {
                foreach ($users as $user) {
                    $names = DB::table($modelHasRoles.' as mhr')
                        ->join($rolesTable.' as r', 'r.id', '=', 'mhr.role_id')
                        ->where('mhr.model_type', $morphType)
                        ->where('mhr.'.$modelKey, $user->id)
                        ->where('r.guard_name', $guard)
                        ->pluck('r.name');

                    if ($names->count() === 1) {
                        DB::table('users')->where('id', $user->id)->update(['role' => $names->first()]);
                        $fixed++;
                    } elseif ($names->isEmpty()) {
                        $noRole[] = $user->id;
                    }
                }
            });

        if ($fixed > 0 || $noRole !== []) {
            Log::info('users.role repaired from Spatie roles', [
                'fixed' => $fixed,
                'users_without_any_role' => $noRole,
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Roles and repaired users.role values are kept on rollback (removing
        // them could lock users out). Only the column default is restored.
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('role')->default('staff')->change();
            });
        }
    }
};
