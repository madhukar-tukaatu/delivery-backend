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
        $manager = $this->createManager($branch, $branchData);
        $staffAccounts = $this->createStaffAccounts($branch);

        return [
            'manager' => $manager,
            'staff_accounts' => $staffAccounts,
            'total_accounts' => 1 + count($staffAccounts),
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

        $email = strtolower(trim((string) ($branchData['email'] ?? '')));

        if ($email === '') {
            throw ValidationException::withMessages([
                'email' => [
                    'A manager email is required to create the branch manager account.',
                ],
            ]);
        }

        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => [
                    'A user account with this email already exists.',
                ],
            ]);
        }

        // Also guard against a leftover position_code from a previous
        // partially-committed transaction attempt.
        if (BranchTeamPosition::query()->where('position_code', $username)->exists()) {
            throw ValidationException::withMessages([
                'email' => [
                    'A branch manager account with this branch code already exists. The previous creation may have partially succeeded — please check existing branches or contact support.',
                ],
            ]);
        }

        $contactPerson = trim(
            (string) ($branchData['contact_person'] ?? '')
        );

        $ownerName = trim(
            (string) ($branchData['owner_name'] ?? '')
        );

        $legalName = trim(
            (string) ($branchData['legal_name'] ?? '')
        );

        $managerName =
            $contactPerson
            ?: $ownerName
            ?: $legalName
            ?: trim((string) $branch->name)
            ?: 'Branch Manager';

        $phone = trim((string) ($branchData['phone'] ?? ''));

        $manager = User::create([
            'branch_id' => $branch->id,
            'merchant_id' => null,
            'username' => $username,
            'name' => $managerName,
            'email' => $email,
            'phone' => $phone !== '' ? $phone : null,
            'role' => $managerRole,
            'password' => Str::random(64),
            'email_verified_at' => now(),
            'is_active' => true,
            'account_status' => User::ACCOUNT_ACTIVE,
            'must_change_password' => true,
            'assigned_at' => now(),
        ]);

        // Guard against duplicate position if a previous transaction
        // rolled back after the user was created but before the position
        // was committed (or if the user already holds a position).
        $existingPosition = BranchTeamPosition::query()
            ->where('user_id', $manager->id)
            ->first();

        if (!$existingPosition) {
            BranchTeamPosition::create([
                'branch_id' => $branch->id,
                'user_id' => $manager->id,
                'role' => $managerRole,
                'position_code' => $username,
                'position_number' => 1,
                'staffing_status' => BranchTeamPosition::STATUS_ASSIGNED,
                'assigned_at' => now(),
            ]);
        }

        return $manager;
    }

    private function createStaffAccounts(Branch $branch): array
    {
        $createdAccounts = [];

        foreach ($this->teamTemplate($branch) as $definition) {
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
                        Str::headline($definition['role']),
                        $position
                    ),
                    'email' => null,
                    'phone' => null,
                    'role' => $definition['role'],
                    'password' => $temporaryPassword,
                    'email_verified_at' => now(),
                    'is_active' => true,
                    'account_status' => User::ACCOUNT_ACTIVE,
                    'must_change_password' => true,
                    'assigned_at' => null,
                ]);

                $existingStaffPosition = BranchTeamPosition::query()
                    ->where('user_id', $user->id)
                    ->first();

                $teamPosition = $existingStaffPosition ?? BranchTeamPosition::create([
                    'branch_id' => $branch->id,
                    'user_id' => $user->id,
                    'role' => $definition['role'],
                    'position_code' => $username,
                    'position_number' => $position,
                    'staffing_status' => BranchTeamPosition::STATUS_VACANT,
                    'temporary_password_encrypted' =>
                        Crypt::encryptString($temporaryPassword),
                ]);

                $createdAccounts[] = [
                    'user' => $user,
                    'position' => $teamPosition,
                ];
            }
        }

        return $createdAccounts;
    }

    private function teamTemplate(Branch $branch): array
    {
        if ($branch->type === Branch::TYPE_SUB_BRANCH) {
            return [
                ['role' => 'booking_staff', 'code' => 'BOOKING', 'quantity' => 1],
                ['role' => 'pickup_staff', 'code' => 'PICKUP', 'quantity' => 1],
                ['role' => 'dispatch_staff', 'code' => 'DISPATCH', 'quantity' => 1],
                ['role' => 'accounts_staff', 'code' => 'ACCOUNTS', 'quantity' => 1],
                ['role' => 'support_staff', 'code' => 'SUPPORT', 'quantity' => 1],
            ];
        }

        return [
            ['role' => 'booking_staff', 'code' => 'BOOKING', 'quantity' => 2],
            ['role' => 'pickup_staff', 'code' => 'PICKUP', 'quantity' => 2],
            ['role' => 'dispatch_staff', 'code' => 'DISPATCH', 'quantity' => 2],
            ['role' => 'accounts_staff', 'code' => 'ACCOUNTS', 'quantity' => 1],
            ['role' => 'support_staff', 'code' => 'SUPPORT', 'quantity' => 1],
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

        while (User::query()->where('username', $username)->exists()) {
            $username = "{$baseUsername}-{$suffix}";
            $suffix++;
        }

        return $username;
    }
}
