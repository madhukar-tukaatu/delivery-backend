<?php

namespace Modules\Billing\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Notification\Models\NotificationLog;
use Throwable;

/**
 * Branch / merchant notifications for billing events: one notification_logs row
 * (the in-app feed) plus an email to the branch manager + branch email (or the
 * merchant email). Mail failures never break the payment flow.
 */
class BillingNotifier
{
    public function notifyBranch(?int $branchId, string $event, string $subject, string $message, array $payload = [], bool $email = true, ?int $merchantId = null): void
    {
        $branch = $branchId ? DB::table('branches')->where('id', $branchId)->first() : null;
        $this->log($branch?->manager_user_id, $merchantId, $event, $branch?->email, $subject, $message, $payload + ['branch_id' => $branchId]);

        if (! $email || ! $branchId) {
            return;
        }
        $emails = app(InterBranchStatementService::class)->branchEmails($branchId);
        $this->mail($emails, $subject, $message);
    }

    public function notifyMerchant(int $merchantId, string $event, string $subject, string $message, array $payload = [], bool $email = true): void
    {
        $merchant = DB::table('merchants')->where('id', $merchantId)->first();
        $this->log(null, $merchantId, $event, $merchant?->email, $subject, $message, $payload + ['merchant_id' => $merchantId]);
        if ($email && $merchant && filter_var($merchant->email ?? '', FILTER_VALIDATE_EMAIL)) {
            $this->mail([$merchant->email], $subject, $message);
        }
    }

    private function log(?int $userId, ?int $merchantId, string $event, ?string $recipient, string $subject, string $message, array $payload): void
    {
        try {
            NotificationLog::create([
                'user_id' => $userId,
                'merchant_id' => $merchantId,
                'channel' => 'system',
                'event' => $event,
                'recipient' => $recipient,
                'subject' => mb_substr($subject, 0, 250),
                'message' => $message,
                'payload' => $payload,
                'status' => 'sent',
                'sent_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::info('billing.notification_log_skipped', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }

    /** @param list<string> $emails */
    private function mail(array $emails, string $subject, string $message): void
    {
        $emails = array_values(array_filter($emails));
        if ($emails === []) {
            return;
        }
        try {
            Mail::raw($message, fn ($m) => $m->to($emails)->subject($subject));
            app(MarketplaceBillingService::class)->logEmail(implode(',', $emails), $subject, $message);
        } catch (Throwable $e) {
            Log::warning('billing.notify_email_failed', ['subject' => $subject, 'error' => $e->getMessage()]);
        }
    }
}