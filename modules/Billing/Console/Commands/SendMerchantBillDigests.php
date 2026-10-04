<?php

namespace Modules\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Billing\Jobs\SendMerchantBillDigest;
use Modules\Billing\Services\MerchantDeliveryBillingService;
use Modules\Merchant\Models\Merchant;
use Modules\Notification\Models\EmailLog;
use Throwable;

class SendMerchantBillDigests extends Command
{
    protected $signature = 'billing:send-merchant-digests {--period=daily : Period (daily|weekly)}';
    protected $description = 'Send periodic delivery charge bill digests to merchants';

    public function handle(): int
    {
        $period = $this->option('period');
        
        if (! in_array($period, ['daily', 'weekly'])) {
            $this->error('Period must be daily or weekly');
            return 1;
        }

        // Scheduler runs this on Monday 06:17. Admin Send weekly does not use this command.
        if ($period === 'weekly' && now()->dayOfWeek !== 1) {
            $this->info('Weekly digest only runs on Mondays. Skipping.');
            return 0;
        }

        $merchants = Merchant::whereNotNull('email')
            ->orWhereNotNull('contact_email')
            ->get();

        $count = 0;
        foreach ($merchants as $merchant) {
            SendMerchantBillDigest::dispatch($merchant->id, $period);
            $count++;
        }

        $this->info("Dispatched {$count} {$period} digest jobs for merchants.");
        $this->sendCompanyDigest($period);

        return 0;
    }

    /**
     * One summary of company (marketplace coupon) invoices. Not sent to merchants.
     */
    protected function sendCompanyDigest(string $period): void
    {
        $billing = app(MerchantDeliveryBillingService::class);
        $toDate = now()->toDateString();
        $fromDate = $period === 'weekly'
            ? now()->subWeek()->toDateString()
            : now()->subDay()->toDateString();

        $invoices = $billing->getUnpaidCompanyInvoices($fromDate, $toDate);
        if ($invoices->isEmpty()) {
            $this->info('No company delivery-coupon invoices for this period.');

            return;
        }

        $email = config('billing.marketplace_email');
        if (! $email) {
            Log::info('delivery_bill.company_digest_email_skipped', [
                'count' => $invoices->count(),
                'reason' => 'BILLING_MARKETPLACE_EMAIL is empty',
            ]);
            $this->warn('BILLING_MARKETPLACE_EMAIL is empty; company invoices were not emailed.');

            return;
        }

        $subject = $period === 'weekly'
            ? 'Weekly marketplace delivery coupon summary'
            : 'Daily marketplace delivery coupon summary';
        $body = $billing->buildDigestEmailBody($invoices, 'company');

        try {
            Mail::raw($body, function ($message) use ($email, $subject) {
                $message->to($email)->subject($subject);
            });
            if (class_exists(EmailLog::class)) {
                EmailLog::create([
                    'to_email' => $email,
                    'subject' => $subject,
                    'body' => $body,
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);
            }
            $billing->stampInvoicesSent($invoices);
                        $this->info('Sent company delivery-coupon summary to '.$email.' ('.$invoices->count().' invoices).');
        } catch (Throwable $e) {
            Log::warning('delivery_bill.company_digest_failed', ['error' => $e->getMessage()]);
            $this->warn('Company digest email failed: '.$e->getMessage());
        }
    }
}
