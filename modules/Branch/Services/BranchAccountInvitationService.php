<?php

namespace Modules\Branch\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Branch\Jobs\SendBranchAccountInvitation;
use Modules\Branch\Models\Branch;

final class BranchAccountInvitationService
{
    public const STATUS_PENDING_ADMIN_APPROVAL =
        'pending_admin_approval';

    public const STATUS_QUEUED =
        'queued';

    public const STATUS_SENT =
        'sent';

    public const STATUS_FAILED =
        'failed';

    public const STATUS_ACCOUNT_CONFIGURED =
        'account_configured';

    public function queue(
        Branch $branch,
        bool $force = false
    ): array {
        return DB::transaction(
            function () use (
                $branch,
                $force
            ): array {
                $lockedBranch = Branch::query()
                    ->with('manager')
                    ->lockForUpdate()
                    ->findOrFail($branch->id);

                if (
                    !in_array(
                        $lockedBranch->status,
                        [
                            Branch::STATUS_APPROVED,
                            Branch::STATUS_ACTIVE,
                        ],
                        true
                    )
                ) {
                    throw ValidationException::withMessages([
                        'branch' => [
                            'The branch must be approved before sending an account invitation.',
                        ],
                    ]);
                }

                $manager = $lockedBranch->manager;

                if (
                    !$manager ||
                    !$lockedBranch->manager_user_id
                ) {
                    throw ValidationException::withMessages([
                        'manager_user_id' => [
                            'A registered Branch Manager account is required.',
                        ],
                    ]);
                }

                if (blank($manager->email)) {
                    throw ValidationException::withMessages([
                        'manager_email' => [
                            'The registered Branch Manager email is missing.',
                        ],
                    ]);
                }

                if (
                    $manager->account_setup_completed_at !==
                    null
                ) {
                    $lockedBranch->forceFill([
                        'account_invitation_status' =>
                            self::STATUS_ACCOUNT_CONFIGURED,

                        'account_invitation_email' =>
                            $manager->email,

                        'account_invitation_error' =>
                            null,
                    ])->save();

                    return $this->payload(
                        $lockedBranch->fresh()
                    );
                }

                if (
                    !$force &&
                    in_array(
                        $lockedBranch
                            ->account_invitation_status,
                        [
                            self::STATUS_QUEUED,
                            self::STATUS_SENT,
                            self::STATUS_ACCOUNT_CONFIGURED,
                        ],
                        true
                    )
                ) {
                    return $this->payload(
                        $lockedBranch
                    );
                }

                $attempt =
                    (int) $lockedBranch
                        ->account_invitation_count + 1;

                $lockedBranch->forceFill([
                    'account_invitation_status' =>
                        self::STATUS_QUEUED,

                    'account_invitation_email' =>
                        $manager->email,

                    'account_invitation_queued_at' =>
                        now(),

                    'account_invitation_failed_at' =>
                        null,

                    'account_invitation_error' =>
                        null,

                    'account_invitation_count' =>
                        $attempt,
                ])->save();

                SendBranchAccountInvitation::dispatch(
                    branchId:
                        (int) $lockedBranch->id,

                    managerUserId:
                        (int) $manager->id,

                    invitationAttempt:
                        $attempt
                )
                    ->onQueue('emails')
                    ->afterCommit();

                return $this->payload(
                    $lockedBranch->fresh()
                );
            },
            3
        );
    }

    public function payload(
        Branch $branch
    ): array {
        return [
            'status' =>
                $branch->account_invitation_status,

            'email' =>
                $branch->account_invitation_email,

            'queued_at' =>
                $branch
                    ->account_invitation_queued_at
                    ?->toISOString(),

            'sent_at' =>
                $branch
                    ->account_invitation_sent_at
                    ?->toISOString(),

            'failed_at' =>
                $branch
                    ->account_invitation_failed_at
                    ?->toISOString(),

            'attempt' =>
                (int) $branch
                    ->account_invitation_count,

            'error' =>
                $branch
                    ->account_invitation_error,
        ];
    }
}