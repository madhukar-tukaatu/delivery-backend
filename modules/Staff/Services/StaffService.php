<?php

declare(strict_types=1);

namespace Modules\Staff\Services;

use App\Models\User;
use App\Notifications\StaffAccountUpdatedNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Role;

final class StaffService
{
    /*
    |--------------------------------------------------------------------------
    | STAFF ROLES
    |--------------------------------------------------------------------------
    |
    | These are roles that can be assigned from Branch Staff management.
    |
    */

    private const STAFF_ROLES = [
        'rider',
        'pickup_rider',
        'delivery_staff',
        'branch_manager',
        'booking_staff',
        'pickup_staff',
        'dispatch_staff',
        'accounts_staff',
        'support_staff',
        'branch_staff',
        'warehouse_staff',
    ];

    /** Roles only global admins may hand out (branch managers cannot). */
    private const ADMIN_ONLY_ROLES = [
        'branch_manager',
    ];

    /** Users who may assign ADMIN_ONLY_ROLES. */
    private const GLOBAL_ADMIN_ROLES = [
        'super_admin',
        'main_admin',
        'admin',
    ];

    /** Form / legacy values mapped to real role names. */
    private const ROLE_ALIASES = [
        'staff' => 'branch_staff',
        'branch' => 'branch_staff',
        'delivery' => 'delivery_staff',
        'delivery_rider' => 'rider',
        'pickup' => 'pickup_staff',
        'booking' => 'booking_staff',
        'dispatch' => 'dispatch_staff',
        'account' => 'accounts_staff',
        'accounts' => 'accounts_staff',
        'account_staff' => 'accounts_staff',
        'support' => 'support_staff',
        'warehouse' => 'warehouse_staff',
        'manager' => 'branch_manager',
    ];

    /*
    |--------------------------------------------------------------------------
    | QUERY FOR USER
    |--------------------------------------------------------------------------
    |
    | This is the security boundary.
    |
    | Global administrators can see all staff.
    |
    | Branch users can ONLY see users belonging to their branch.
    |
    */

    public function queryForUser(
        User $user
    ): Builder {
        $query = User::query()
            ->with([
                'roles:id,name',
                'branch:id,name',
            ])
            ->whereHas(
                'roles',
                function (
                    Builder $roleQuery
                ): void {
                    $roleQuery->whereIn(
                        'name',
                        self::STAFF_ROLES
                    );
                }
            );

        /*
        |--------------------------------------------------------------------------
        | Global administrator
        |--------------------------------------------------------------------------
        */

        if (
            $user->hasAnyRole([
                'super_admin',
                'admin',
            ])
        ) {
            return $query
                ->orderBy(
                    'name'
                );
        }

        /*
        |--------------------------------------------------------------------------
        | Branch manager / branch staff
        |--------------------------------------------------------------------------
        */

        if ($user->branch_id === null) {
            return $query->whereRaw(
                '1 = 0'
            );
        }

        return $query
            ->where(
                'branch_id',
                (int) $user->branch_id
            )
            ->orderBy(
                'name'
            );
    }

    /*
    |--------------------------------------------------------------------------
    | FIND STAFF FOR USER
    |--------------------------------------------------------------------------
    */

    public function findForUser(
        User $user,
        int $staffId
    ): ?User {
        return $this->queryForUser(
            $user
        )->whereKey(
            $staffId
        )->first();
    }

    /*
    |--------------------------------------------------------------------------
    | AVAILABLE ROLES
    |--------------------------------------------------------------------------
    |
    | Branch managers don't need roles.view.
    |
    | They only get staff-related roles.
    |
    */

    public function availableRolesForUser(
        User $user
    ) {
        $query = Role::query()
            ->where(
                'guard_name',
                $this->guardName()
            )
            ->whereIn(
                'name',
                $this->assignableRolesFor($user)
            )
            ->orderBy(
                'name'
            );

        return $query->get([
            'id',
            'name',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE
    |--------------------------------------------------------------------------
    */

    public function createForUser(
        User $creator,
        array $data
    ): User {
        $roleName = $this->resolveRole(
            $creator,
            $data['role'] ?? null
        );

        $branchId = $this->resolveBranchId(
            $creator,
            $data['branch_id'] ?? null
        );

        $staff = new User();

        $staff->name =
            $data['name'];

        $staff->email =
            $data['email'];

        $staff->phone =
            $data['phone'] ?? null;

        $staff->password =
            Hash::make(
                $data['password']
            );

        $staff->branch_id =
            $branchId;

        // Keep the legacy users.role column in sync with the Spatie role so
        // later saves never fall back to the old DB default ("staff").
        $staff->role =
            $roleName;

        $staff->is_active =
            array_key_exists(
                'is_active',
                $data
            )
                ? (bool) $data['is_active']
                : true;

        $staff->save();

        $staff->syncRoles([
            $roleName,
        ]);

        return $staff->load([
            'roles:id,name',
            'branch:id,name',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    public function updateForUser(
        User $editor,
        User $staff,
        array $data
    ): User {
        $roleName = $this->resolveRole(
            $editor,
            $data['role'] ?? null,
            $staff
        );

        /*
        |--------------------------------------------------------------------------
        | Never allow branch managers to move
        | staff into another branch.
        |--------------------------------------------------------------------------
        */

        if (
            ! $editor->hasAnyRole([
                'super_admin',
                'admin',
            ])
        ) {
            $staff->branch_id =
                $editor->branch_id;
        }

        $oldName = $staff->name;
        $oldEmail = $staff->email;
        $oldPassword = $staff->password;

        $staff->name =
            $data['name'];

        $staff->email =
            $data['email'];

        $staff->phone =
            $data['phone'] ?? null;

        $passwordChanged = false;
        if (
            ! empty(
                $data['password']
            )
        ) {
            $staff->password =
                Hash::make(
                    $data['password']
                );
            $passwordChanged = true;
        }

        if (
            array_key_exists(
                'is_active',
                $data
            )
        ) {
            $staff->is_active =
                (bool) $data['is_active'];
        }

        $staff->role =
            $roleName;

        $staff->save();

        $staff->syncRoles([
            $roleName,
        ]);

        // Send notification if email or password was changed
        $changes = [];
        
        if ($oldEmail !== $staff->email) {
            $changes['email'] = [
                'old' => $oldEmail,
                'new' => $staff->email,
            ];
        }
        
        if ($passwordChanged) {
            $changes['password'] = [
                'changed' => true,
            ];
        }
        
        if ($oldName !== $data['name']) {
            $changes['name'] = $data['name'];
        }
        
        if (!empty($changes)) {
            $staff->notify(
                new StaffAccountUpdatedNotification($changes)
            );
        }

        return $staff->load([
            'roles:id,name',
            'branch:id,name',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | TOGGLE
    |--------------------------------------------------------------------------
    */

    public function toggleForUser(
        User $editor,
        User $staff
    ): User {
        $staff->is_active =
            ! (bool) $staff->is_active;

        $staff->save();

        return $staff->load([
            'roles:id,name',
            'branch:id,name',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | DEACTIVATE
    |--------------------------------------------------------------------------
    */

    public function deactivateForUser(
        User $editor,
        User $staff
    ): void {
        $staff->is_active = false;

        $staff->save();
    }

    /*
    |--------------------------------------------------------------------------
    | RESOLVE BRANCH
    |--------------------------------------------------------------------------
    */

    private function resolveBranchId(
        User $creator,
        mixed $requestedBranchId = null
    ): ?int {
        if (
            $creator->hasAnyRole([
                'super_admin',
                'admin',
            ])
        ) {
            // Global admins may pick the branch; default to their own.
            if ($requestedBranchId !== null && $requestedBranchId !== '') {
                return (int) $requestedBranchId;
            }

            return $creator->branch_id !== null
                ? (int) $creator->branch_id
                : null;
        }

        // Branch managers always create staff in their own branch.
        if ($creator->branch_id === null) {
            throw ValidationException::withMessages([
                'branch_id' => 'Your account is not assigned to a branch, so you cannot create branch staff.',
            ]);
        }

        return (int) $creator->branch_id;
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATE ROLE
    |--------------------------------------------------------------------------
    */

    /**
     * Roles $user may assign from branch staff management.
     *
     * @return list<string>
     */
    private function assignableRolesFor(
        User $user
    ): array {
        if ($user->hasAnyRole(self::GLOBAL_ADMIN_ROLES)) {
            return self::STAFF_ROLES;
        }

        return array_values(array_diff(
            self::STAFF_ROLES,
            self::ADMIN_ONLY_ROLES
        ));
    }

    private function guardName(): string
    {
        return Guard::getDefaultName(User::class);
    }

    /**
     * Normalise the requested role (aliases like "staff" -> "branch_staff"),
     * check the actor may assign it and that it exists for the users' guard.
     * Problems are returned as 422 validation errors, never a 500.
     */
    private function resolveRole(
        User $actor,
        ?string $requested,
        ?User $staff = null
    ): string {
        $role = strtolower(trim((string) $requested));
        $role = (string) preg_replace('/[\s\-]+/', '_', $role);
        $role = self::ROLE_ALIASES[$role] ?? $role;

        if ($role === '' || ! in_array($role, self::STAFF_ROLES, true)) {
            throw ValidationException::withMessages([
                'role' => 'The selected role cannot be assigned from branch staff management.',
            ]);
        }

        // Editing someone who already has this role is fine (no escalation).
        $unchanged = $staff !== null && $staff->hasRole($role);

        if (! $unchanged && ! in_array($role, $this->assignableRolesFor($actor), true)) {
            throw ValidationException::withMessages([
                'role' => 'You are not allowed to assign the "'.$role.'" role.',
            ]);
        }

        $exists = Role::query()
            ->where('name', $role)
            ->where('guard_name', $this->guardName())
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'role' => 'The "'.$role.'" role is not set up on this server yet. Ask an administrator to run the latest migrations or create the role.',
            ]);
        }

        return $role;
    }
}