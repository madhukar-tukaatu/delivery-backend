<?php

namespace Modules\Branch\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Modules\Branch\Mail\BranchAccountInvitationMail;
use Modules\Branch\Models\Branch;
use Modules\Branch\Services\BranchAccountInvitationService;
use RuntimeException;
use Throwable;

final class SendBranchAccountInvitation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    public function __construct(
        public readonly int $branchId,
        public readonly int $managerUserId,
        public readonly int $invitationAttempt
    ) {
    }

    public function backoff(): array
    {
        return [
            60,
            300,
            900,
            1800,
        ];
    }

    public function handle(): void
    {
        $branch = Branch::query()
            ->with([
                'manager',
                'coverageLocation',
            ])
            ->findOrFail($this->branchId);

        /*
         * Ignore an older queued job after an admin has
         * already requested a newer invitation.
         */
        if (
            (int) $branch
                ->account_invitation_count !==
            $this->invitationAttempt
        ) {
            Log::info(
                'Stale branch invitation job ignored.',
                [
                    'branch_id' => $branch->id,
                    'job_attempt' =>
                        $this->invitationAttempt,
                    'current_attempt' =>
                        $branch
                            ->account_invitation_count,
                ]
            );

            return;
        }

        try {
            if (
                !in_array(
                    $branch->status,
                    [
                        Branch::STATUS_APPROVED,
                        Branch::STATUS_ACTIVE,
                    ],
                    true
                )
            ) {
                throw new RuntimeException(
                    'The branch is not approved.'
                );
            }

            $manager = User::query()
                ->findOrFail(
                    $this->managerUserId
                );

            if (
                (int) $branch->manager_user_id !==
                (int) $manager->id
            ) {
                throw new RuntimeException(
                    'The manager user does not belong to this branch.'
                );
            }

            if (blank($manager->email)) {
                throw new RuntimeException(
                    'The Branch Manager email is missing.'
                );
            }

            if (
                $manager
                    ->account_setup_completed_at !==
                null
            ) {
                $branch->forceFill([
                    'account_invitation_status' =>
                        BranchAccountInvitationService::
                            STATUS_ACCOUNT_CONFIGURED,

                    'account_invitation_email' =>
                        $manager->email,

                    'account_invitation_error' =>
                        null,
                ])->save();

                return;
            }

            /*
             * A resend invalidates the previous password
             * setup token.
             */
            Password::broker()
                ->deleteToken($manager);

            $token = Password::broker()
                ->createToken($manager);

            $frontendUrl = rtrim(
                (string) config(
                    'app.frontend_url',
                    'http://localhost:3000'
                ),
                '/'
            );

            if ($frontendUrl === '') {
                throw new RuntimeException(
                    'FRONTEND_URL is not configured.'
                );
            }

            $setPasswordUrl =
                $frontendUrl
                . '/set-initial-password?'
                . http_build_query([
                    'token' => $token,
                    'email' => $manager->email,
                ]);

            $loginUrl =
                $frontendUrl . '/login';

            Mail::to(
                $manager->email,
                $manager->name
            )->send(
                new BranchAccountInvitationMail(
                    branch: $branch,
                    manager: $manager,
                    setPasswordUrl:
                        $setPasswordUrl,
                    loginUrl:
                        $loginUrl
                )
            );

            Branch::query()
                ->where('id', $branch->id)
                ->where(
                    'account_invitation_count',
                    $this->invitationAttempt
                )
                ->update([
                    'account_invitation_status' =>
                        BranchAccountInvitationService::
                            STATUS_SENT,

                    'account_invitation_email' =>
                        $manager->email,

                    'account_invitation_sent_at' =>
                        now(),

                    'account_invitation_failed_at' =>
                        null,

                    'account_invitation_error' =>
                        null,

                    'updated_at' =>
                        now(),
                ]);

            Log::info(
                'Branch Manager account invitation sent.',
                [
                    'branch_id' =>
                        $branch->id,

                    'manager_user_id' =>
                        $manager->id,

                    'email' =>
                        $manager->email,

                    'attempt' =>
                        $this->invitationAttempt,
                ]
            );
        } catch (Throwable $exception) {
            Branch::query()
                ->where('id', $branch->id)
                ->where(
                    'account_invitation_count',
                    $this->invitationAttempt
                )
                ->update([
                    'account_invitation_status' =>
                        BranchAccountInvitationService::
                            STATUS_FAILED,

                    'account_invitation_failed_at' =>
                        now(),

                    'account_invitation_error' =>
                        Str::limit(
                            $exception->getMessage(),
                            2000
                        ),

                    'updated_at' =>
                        now(),
                ]);

            throw $exception;
        }
    }

    public function failed(
        Throwable $exception
    ): void {
        Log::error(
            'Branch Manager invitation job failed.',
            [
                'branch_id' =>
                    $this->branchId,

                'manager_user_id' =>
                    $this->managerUserId,

                'attempt' =>
                    $this->invitationAttempt,

                'error' =>
                    $exception->getMessage(),
            ]
        );
    }
}