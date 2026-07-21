<?php

namespace Modules\Branch\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Branch\Mail\BranchCreatedMail;
use Modules\Branch\Models\Branch;
use Throwable;

class SendBranchCreatedNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;

    public function __construct(
        public int $branchId,
        public int $managerUserId,
        public int $generatedUserCount
    ) {
    }

    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function handle(): void
    {
        $branch = Branch::query()
            ->with([
                'manager',
                'coverageLocation',
            ])
            ->findOrFail($this->branchId);

        $manager = $branch->manager;

        if (!$manager) {
            throw new \RuntimeException(
                "Manager user {$this->managerUserId} was not found."
            );
        }

        if (blank($manager->email)) {
            throw new \RuntimeException(
                'Branch manager email is missing.'
            );
        }

        Mail::to($manager->email)->send(
            new BranchCreatedMail(
                branch: $branch,
                manager: $manager,
                generatedUserCount: $this->generatedUserCount
            )
        );

        Log::info('Branch creation notification sent.', [
            'branch_id' => $branch->id,
            'manager_user_id' => $manager->id,
            'email' => $manager->email,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Branch creation notification failed.', [
            'branch_id' => $this->branchId,
            'manager_user_id' => $this->managerUserId,
            'error' => $exception->getMessage(),
        ]);
    }
}