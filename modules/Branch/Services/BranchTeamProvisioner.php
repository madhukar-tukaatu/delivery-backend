<?php

namespace Modules\Branch\Services;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Branch\Models\Branch;
use Modules\Branch\Models\BranchTeamPosition;

class BranchTeamProvisioner
{
    public function provision(
        Branch $branch,
        array $branchData
    ): array {
        $manager = $this->createManager(
            $branch,
            $branchData
        );

        $staffAccounts = $this->createStaffAccounts(
            $branch
        );

        return [
            'manager' => $manager,
            'staff_accounts' => $staffAccounts,
            'total_accounts' =>
                1 + count($staffAccounts),
        ];
    }

    private function createManager(
        Branch $branch,
        array $branchData
    ): User {
        $managerRole =
            $branch->type === Branch::TYPE_SUB_BRANCH
                ? 'sub_branch_manager'
                : 'branch_manager';

        $username = $this->generateUsername(
            $branch,
            'MANAGER',
            1
        );

        $email = strtolower(
            trim((string) $branchData['email'])
        );

        if (
            User::query()
                ->where('email', $email)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'email' => [
                    'A user account with this email already exists.',
                ],
            ]);
        }

        $manager = User::create([
            'branch_id' => $branch->id,
            'merchant_id' => null,

            'username' => $username,

            'name' => $branchData['contact_person']
                ?: $branchData['owner_name']
                ?: $branchData['legal_name']
                ?: 'Branch Manager',

            'email' => $email,
            'phone' => $branchData['phone'],

            'role' => $managerRole,

            /*
             * The owner sets a real password using the
             * password setup link received by email.
             */
            'password' => Str::random(64),

            /*
             * This project does not require email verification.
             */
            'email_verified_at' => now(),

            'is_active' => true,
            'account_status' => User::ACCOUNT_ACTIVE,
            'must_change_password' => true,
            'assigned_at' => now(),
        ]);

        BranchTeamPosition::create([
            'branch_id' => $branch->id,
            'user_id' => $manager->id,
            'role' => $managerRole,
            'position_code' => $username,
            'position_number' => 1,
            'staffing_status' =>
                BranchTeamPosition::STATUS_ASSIGNED,
            'assigned_at' => now(),
        ]);

        return $manager;
    }

    private function createStaffAccounts(
        Branch $branch
    ): array {
        $createdAccounts = [];

        foreach (
            $this->teamTemplate($branch) as $definition
        ) {
            for (
                $position = 1;
                $position <= $definition['quantity'];
                $position++
            ) {
                $username = $this->generateUsername(
                    $branch,
                    $definition['code'],
                    $position
                );

                $temporaryPassword = Str::password(14);

                $user = User::create([
                    'branch_id' => $branch->id,
                    'merchant_id' => null,

                    'username' => $username,

                    'name' => sprintf(
                        '%s %02d',
                        Str::headline(
                            $definition['role']
                        ),
                        $position
                    ),

                    'email' => null,
                    'phone' => null,

                    'role' => $definition['role'],

                    /*
                     * The User model hashes this automatically.
                     */
                    'password' => $temporaryPassword,

                    /*
                     * Verification is not applicable to
                     * position-based accounts.
                     */
                    'email_verified_at' => now(),

                    /*
                     * Vacant positions may still access the system.
                     */
                    'is_active' => true,
                    'account_status' => User::ACCOUNT_ACTIVE,
                    'must_change_password' => true,
                    'assigned_at' => null,
                ]);

                $teamPosition =
                    BranchTeamPosition::create([
                        'branch_id' => $branch->id,
                        'user_id' => $user->id,
                        'role' => $definition['role'],
                        'position_code' => $username,
                        'position_number' => $position,
                        'staffing_status' =>
                            BranchTeamPosition::STATUS_VACANT,

                        /*
                         * The Branch Manager may reveal this once.
                         */
                        'temporary_password_encrypted' =>
                            Crypt::encryptString(
                                $temporaryPassword
                            ),
                    ]);

                $createdAccounts[] = [
                    'user' => $user,
                    'position' => $teamPosition,
                ];
            }
        }

        return $createdAccounts;
    }

    private function teamTemplate(
        Branch $branch
    ): array {
        if (
            $branch->type === Branch::TYPE_SUB_BRANCH
        ) {
            return [
                [
                    'role' => 'booking_staff',
                    'code' => 'BOOKING',
                    'quantity' => 1,
                ],
                [
                    'role' => 'pickup_staff',
                    'code' => 'PICKUP',
                    'quantity' => 1,
                ],
                [
                    'role' => 'dispatch_staff',
                    'code' => 'DISPATCH',
                    'quantity' => 1,
                ],
                [
                    'role' => 'accounts_staff',
                    'code' => 'ACCOUNTS',
                    'quantity' => 1,
                ],
                [
                    'role' => 'support_staff',
                    'code' => 'SUPPORT',
                    'quantity' => 1,
                ],
            ];
        }

        return [
            [
                'role' => 'booking_staff',
                'code' => 'BOOKING',
                'quantity' => 2,
            ],
            [
                'role' => 'pickup_staff',
                'code' => 'PICKUP',
                'quantity' => 2,
            ],
            [
                'role' => 'dispatch_staff',
                'code' => 'DISPATCH',
                'quantity' => 2,
            ],
            [
                'role' => 'accounts_staff',
                'code' => 'ACCOUNTS',
                'quantity' => 1,
            ],
            [
                'role' => 'support_staff',
                'code' => 'SUPPORT',
                'quantity' => 1,
            ],
        ];
    }

    private function generateUsername(
        Branch $branch,
        string $roleCode,
        int $position
    ): string {
        $branchCode = Str::of(
            $branch->code ?: "BRANCH-{$branch->id}"
        )
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', '-')
            ->trim('-');

        $baseUsername = sprintf(
            '%s-%s-%02d',
            $branchCode,
            strtoupper($roleCode),
            $position
        );

        $username = $baseUsername;
        $suffix = 1;

        while (
            User::query()
                ->where('username', $username)
                ->exists()
        ) {
            $username =
                "{$baseUsername}-{$suffix}";

            $suffix++;
        }

        return $username;
    }
}