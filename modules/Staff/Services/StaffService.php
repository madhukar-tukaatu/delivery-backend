<?php

declare(strict_types=1);

namespace Modules\Staff\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Staff\Models\Staff;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class StaffService
{
    /*
    |--------------------------------------------------------------------------
    | LIST
    |--------------------------------------------------------------------------
    */

    public function paginate(
        Request $request
    ): LengthAwarePaginator {
        $branchId =
            $this->currentBranchId();

        $query =
            Staff::query()
                ->with([
                    'roles:id,name',
                    'branch',
                ])
                ->where(
                    'branch_id',
                    $branchId
                );

        $search =
            trim(
                (string) $request->input(
                    'q',
                    ''
                )
            );

        if ($search !== '') {
            $query->where(function (
                Builder $builder
            ) use ($search) {

                $builder
                    ->where(
                        'name',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'email',
                        'like',
                        "%{$search}%"
                    )
                    ->orWhere(
                        'phone',
                        'like',
                        "%{$search}%"
                    );
            });
        }

        return $query
            ->latest('id')
            ->paginate(
                min(
                    (int) $request->input(
                        'per_page',
                        20
                    ),
                    100
                )
            );
    }

    /*
    |--------------------------------------------------------------------------
    | FIND
    |--------------------------------------------------------------------------
    */

    public function findForCurrentUser(
        int $staffId
    ): Staff {
        return Staff::query()
            ->with([
                'roles:id,name',
                'branch',
            ])
            ->where(
                'branch_id',
                $this->currentBranchId()
            )
            ->findOrFail(
                $staffId
            );
    }

    /*
    |--------------------------------------------------------------------------
    | ASSIGNABLE ROLES
    |--------------------------------------------------------------------------
    */

    public function assignableRoles()
    {
        return Role::query()
            ->where(
                'guard_name',
                'web'
            )
            ->whereNotIn(
                'name',
                [
                    'super_admin',
                    'branch_manager',
                ]
            )
            ->orderBy('name')
            ->get([
                'id',
                'name',
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CREATE
    |--------------------------------------------------------------------------
    */

    public function create(
        array $data
    ): Staff {
        $branchId =
            $this->currentBranchId();

        /*
        |--------------------------------------------------------------------------
        | Never accept branch_id from frontend.
        |--------------------------------------------------------------------------
        */

        unset(
            $data['branch_id']
        );

        return DB::transaction(
            function () use (
                $data,
                $branchId
            ): Staff {

                $roleIds =
                    $data['role_ids']
                    ?? [];

                unset(
                    $data['role_ids']
                );

                /*
                |--------------------------------------------------------------------------
                | Password
                |--------------------------------------------------------------------------
                */

                if (
                    isset(
                        $data['password']
                    )
                ) {
                    $data['password'] =
                        Hash::make(
                            $data['password']
                        );
                }

                /*
                |--------------------------------------------------------------------------
                | Generate invitation token if
                | your application uses invitations.
                |--------------------------------------------------------------------------
                */

                $staff =
                    Staff::create(
                        array_merge(
                            $data,
                            [
                                'branch_id' =>
                                    $branchId,

                                'is_active' =>
                                    true,
                            ]
                        )
                    );

                /*
                |--------------------------------------------------------------------------
                | Only assign allowed roles.
                |--------------------------------------------------------------------------
                */

                $roles =
                    Role::query()
                        ->whereIn(
                            'id',
                            $roleIds
                        )
                        ->whereNotIn(
                            'name',
                            [
                                'super_admin',
                                'branch_manager',
                            ]
                        )
                        ->get();

                $staff->syncRoles(
                    $roles
                );

                return $staff->load([
                    'roles',
                    'branch',
                ]);
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE
    |--------------------------------------------------------------------------
    */

    public function update(
        int $staffId,
        array $data
    ): Staff {
        $staff =
            $this->findForCurrentUser(
                $staffId
            );

        unset(
            $data['branch_id']
        );

        return DB::transaction(
            function () use (
                $staff,
                $data
            ): Staff {

                $roleIds =
                    $data['role_ids']
                    ?? null;

                unset(
                    $data['role_ids']
                );

                if (
                    isset(
                        $data['password']
                    ) &&
                    filled(
                        $data['password']
                    )
                ) {
                    $data['password'] =
                        Hash::make(
                            $data['password']
                        );
                } else {
                    unset(
                        $data['password']
                    );
                }

                $staff->update(
                    $data
                );

                if (
                    is_array(
                        $roleIds
                    )
                ) {
                    $roles =
                        Role::query()
                            ->whereIn(
                                'id',
                                $roleIds
                            )
                            ->whereNotIn(
                                'name',
                                [
                                    'super_admin',
                                    'branch_manager',
                                ]
                            )
                            ->get();

                    $staff->syncRoles(
                        $roles
                    );
                }

                return $staff->fresh([
                    'roles',
                    'branch',
                ]);
            }
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DEACTIVATE
    |--------------------------------------------------------------------------
    */

    public function deactivate(
        int $staffId
    ): void {
        $staff =
            $this->findForCurrentUser(
                $staffId
            );

        $staff->update([
            'is_active' => false,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | TOGGLE STATUS
    |--------------------------------------------------------------------------
    */

    public function toggleStatus(
        int $staffId
    ): Staff {
        $staff =
            $this->findForCurrentUser(
                $staffId
            );

        $staff->update([
            'is_active' =>
                !$staff->is_active,
        ]);

        return $staff->fresh([
            'roles',
            'branch',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CURRENT BRANCH
    |--------------------------------------------------------------------------
    */

    private function currentBranchId(): int
    {
        $user =
            auth()->user();

        if (!$user) {
            throw new AccessDeniedHttpException(
                'Unauthenticated.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | IMPORTANT
        |--------------------------------------------------------------------------
        |
        | Adjust this one part if your User model stores the branch
        | relationship differently.
        |
        */

        $branchId =
            $user->branch_id
            ?? $user->branch?->id;

        if (!$branchId) {
            throw new AccessDeniedHttpException(
                'Your account is not assigned to a branch.'
            );
        }

        return (int) $branchId;
    }
}