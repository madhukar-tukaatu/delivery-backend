<?php

declare(strict_types=1);

namespace Modules\Staff\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use RuntimeException;

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
        'staff',
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
                'web'
            )
            ->whereIn(
                'name',
                self::STAFF_ROLES
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
        $roleName = $this->validateRole(
            $data['role'] ?? null
        );

        $branchId = $this->resolveBranchId(
            $creator
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
        $roleName = $this->validateRole(
            $data['role'] ?? null
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

        $staff->name =
            $data['name'];

        $staff->email =
            $data['email'];

        $staff->phone =
            $data['phone'] ?? null;

        if (
            ! empty(
                $data['password']
            )
        ) {
            $staff->password =
                Hash::make(
                    $data['password']
                );
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
        User $creator
    ): ?int {
        if (
            $creator->hasAnyRole([
                'super_admin',
                'admin',
            ])
        ) {
            /*
             * Global admins must explicitly provide
             * a branch in the future if cross-branch
             * staff creation is required.
             */
            return $creator->branch_id !== null
                ? (int) $creator->branch_id
                : null;
        }

        if ($creator->branch_id === null) {
            throw new RuntimeException(
                'The authenticated user is not assigned to a branch.'
            );
        }

        return (int) $creator->branch_id;
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATE ROLE
    |--------------------------------------------------------------------------
    */

    private function validateRole(
        ?string $role
    ): string {
        $role = trim(
            (string) $role
        );

        if (
            $role === '' ||
            ! in_array(
                $role,
                self::STAFF_ROLES,
                true
            )
        ) {
            throw new RuntimeException(
                'The selected role cannot be assigned from branch staff management.'
            );
        }

        return $role;
    }
}