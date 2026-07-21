<?php

namespace Modules\Branch\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\Branch\Models\Branch;
use Modules\Branch\Models\BranchTeamPosition;

class BranchTeamController extends Controller
{
    public function index(
        Request $request,
        Branch $branch
    ): JsonResponse {
        $this->authorizeBranchAction(
            $request,
            $branch,
            'branches.team.view'
        );

        return response()->json([
            'data' => $branch
                ->teamPositions()
                ->with([
                    'user:id,branch_id,username,name,email,phone,role,is_active,account_status,must_change_password,assigned_at',
                    'assigner:id,name,email',
                ])
                ->orderBy('role')
                ->orderBy('position_number')
                ->get(),
        ]);
    }

    public function revealCredentials(
        Request $request,
        Branch $branch
    ): JsonResponse {
        $this->authorizeBranchAction(
            $request,
            $branch,
            'branches.team.credentials'
        );

        $credentials = DB::transaction(
            function () use ($branch) {
                $positions = $branch
                    ->teamPositions()
                    ->with('user')
                    ->whereNotNull(
                        'temporary_password_encrypted'
                    )
                    ->lockForUpdate()
                    ->get();

                $result = [];

                foreach ($positions as $position) {
                    $result[] = [
                        'position_id' => $position->id,
                        'position_code' =>
                            $position->position_code,
                        'username' =>
                            $position->user->username,
                        'role' => $position->role,
                        'temporary_password' =>
                            Crypt::decryptString(
                                $position
                                    ->temporary_password_encrypted
                            ),
                    ];

                    /*
                     * Password can only be revealed once.
                     * It can be reset again later.
                     */
                    $position->update([
                        'temporary_password_encrypted' =>
                            null,
                        'credentials_revealed_at' =>
                            now(),
                    ]);
                }

                return $result;
            }
        );

        return response()->json([
            'message' =>
                'Credentials revealed successfully. Store them securely because they cannot be shown again.',

            'data' => $credentials,
        ]);
    }

    public function assign(
        Request $request,
        Branch $branch,
        BranchTeamPosition $position
    ): JsonResponse {
        $this->authorizeBranchAction(
            $request,
            $branch,
            'branches.team.manage'
        );

        $this->ensurePositionBelongsToBranch(
            $position,
            $branch
        );

        if (
            in_array(
                $position->role,
                [
                    'branch_manager',
                    'sub_branch_manager',
                ],
                true
            )
        ) {
            return response()->json([
                'message' =>
                    'The primary manager position cannot be assigned from this action.',
            ], 422);
        }

        $user = $position->user;

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')
                    ->ignore($user->id),
            ],

            'phone' => [
                'nullable',
                'string',
                'max:50',
            ],
        ]);

        $temporaryPassword = Str::password(14);

        DB::transaction(function () use (
            $request,
            $user,
            $position,
            $data,
            $temporaryPassword
        ) {
            $user->update([
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'password' => $temporaryPassword,
                'email_verified_at' =>
                    !empty($data['email'])
                        ? now()
                        : null,
                'is_active' => true,
                'account_status' =>
                    User::ACCOUNT_ACTIVE,
                'must_change_password' => true,
                'assigned_at' => now(),
            ]);

            $position->update([
                'staffing_status' =>
                    BranchTeamPosition::STATUS_ASSIGNED,

                'assigned_by' =>
                    $request->user()->id,

                'assigned_at' => now(),
                'unassigned_at' => null,

                'temporary_password_encrypted' =>
                    null,

                'credentials_revealed_at' => now(),
            ]);

            $this->revokeExistingAccess($user);
        });

        return response()->json([
            'message' =>
                'Employee assigned and account credentials reset successfully.',

            'data' => [
                'position_id' => $position->id,
                'username' => $user->username,
                'temporary_password' =>
                    $temporaryPassword,
                'must_change_password' => true,
            ],
        ]);
    }

    public function unassign(
        Request $request,
        Branch $branch,
        BranchTeamPosition $position
    ): JsonResponse {
        $this->authorizeBranchAction(
            $request,
            $branch,
            'branches.team.manage'
        );

        $this->ensurePositionBelongsToBranch(
            $position,
            $branch
        );

        if (
            in_array(
                $position->role,
                [
                    'branch_manager',
                    'sub_branch_manager',
                ],
                true
            )
        ) {
            return response()->json([
                'message' =>
                    'The primary manager cannot be unassigned using this action.',
            ], 422);
        }

        $user = $position->user;
        $temporaryPassword = Str::password(14);

        DB::transaction(function () use (
            $user,
            $position,
            $temporaryPassword
        ) {
            $user->update([
                'name' => sprintf(
                    '%s %02d',
                    Str::headline($position->role),
                    $position->position_number
                ),
                'email' => null,
                'phone' => null,
                'password' => $temporaryPassword,
                'email_verified_at' => now(),
                'is_active' => true,
                'account_status' =>
                    User::ACCOUNT_ACTIVE,
                'must_change_password' => true,
                'assigned_at' => null,
            ]);

            $position->update([
                'staffing_status' =>
                    BranchTeamPosition::STATUS_VACANT,

                'assigned_by' => null,
                'assigned_at' => null,
                'unassigned_at' => now(),

                'temporary_password_encrypted' =>
                    Crypt::encryptString(
                        $temporaryPassword
                    ),

                'credentials_revealed_at' => null,
            ]);

            $this->revokeExistingAccess($user);
        });

        return response()->json([
            'message' =>
                'Employee removed. The position remains active and new credentials are ready to be revealed.',
        ]);
    }

    public function resetCredentials(
        Request $request,
        Branch $branch,
        BranchTeamPosition $position
    ): JsonResponse {
        $this->authorizeBranchAction(
            $request,
            $branch,
            'branches.team.credentials'
        );

        $this->ensurePositionBelongsToBranch(
            $position,
            $branch
        );

        $temporaryPassword = Str::password(14);
        $user = $position->user;

        DB::transaction(function () use (
            $user,
            $position,
            $temporaryPassword
        ) {
            $user->update([
                'password' => $temporaryPassword,
                'must_change_password' => true,
                'is_active' => true,
                'account_status' =>
                    User::ACCOUNT_ACTIVE,
            ]);

            $position->update([
                /*
                 * The password is being returned in this response,
                 * so treat it as already revealed.
                 */
                'temporary_password_encrypted' =>
                    null,

                'credentials_revealed_at' => now(),
            ]);

            $this->revokeExistingAccess($user);
        });

        return response()->json([
            'message' =>
                'Position credentials reset successfully.',

            'data' => [
                'username' => $user->username,
                'temporary_password' =>
                    $temporaryPassword,
                'must_change_password' => true,
            ],
        ]);
    }

    private function authorizeBranchAction(
        Request $request,
        Branch $branch,
        string $permission
    ): void {
        $user = $request->user();

        abort_unless(
            $user && $user->can($permission),
            403,
            'You are not allowed to manage this branch team.'
        );

        /*
         * Super and main admins can manage any branch.
         * Branch managers are restricted to their own branch.
         */
        if (
            !$user->hasAnyRole([
                'super_admin',
                'main_admin',
            ])
        ) {
            abort_unless(
                (int) $user->branch_id ===
                (int) $branch->id,
                403,
                'You can only manage your own branch.'
            );
        }
    }

    private function ensurePositionBelongsToBranch(
        BranchTeamPosition $position,
        Branch $branch
    ): void {
        abort_unless(
            (int) $position->branch_id ===
            (int) $branch->id,
            404,
            'The position does not belong to this branch.'
        );
    }

    private function revokeExistingAccess(
        User $user
    ): void {
        /*
         * Revoke Sanctum API tokens.
         */
        $user->tokens()->delete();

        /*
         * Remove existing web sessions.
         */
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->delete();
    }
}