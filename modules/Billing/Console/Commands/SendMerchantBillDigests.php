<?php

namespace Modules\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Billing\Jobs\SendMerchantBillDigest;
use Modules\Billing\Services\MerchantDeliveryBillingService;
use Modules\Merchant\Models\Merchant;
use Modules\Setting\Models\Marketplace;
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
     * Marketplace free-delivery invoices not yet on a branch marketplace bill,
     * one summary per marketplace, sent to that marketplace's billing email
     * (BILLING_MARKETPLACE_EMAIL only as fallback). Not sent to merchants.
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
            $this->info('No unbilled marketplace free-delivery invoices for this period.');

            return;
        }

        $fallback = trim((string) config('billing.marketplace_email'));
        $groups = $invoices->groupBy(fn ($inv) => (int) ($billing->invoiceMarketplace($inv)?->id ?? 0));

        foreach ($groups as $marketplaceId => $group) {
            $marketplace = $marketplaceId ? Marketplace::query()->find($marketplaceId) : null;
            $name = $marketplace?->name ?: 'Marketplace';
            $email = trim((string) ($marketplace?->email ?? '')) ?: $fallback;

            if ($email === '') {
                Log::info('delivery_bill.company_digest_email_skipped', [
                    'marketplace_id' => $marketplaceId ?: null,
                    'count' => $group->count(),
                    'reason' => 'Marketplace billing email and BILLING_MARKETPLACE_EMAIL are empty',
                ]);
                $this->warn("{$name}: no billing email; {$group->count()} invoice(s) not emailed. Set it on Admin -> Marketplaces.");

                continue;
            }

            $subject = ($period === 'weekly' ? 'Weekly' : 'Daily').' free-delivery charges summary for '.$name;
            $body = $billing->buildDigestEmailBody($group, 'company', $name);

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
                $billing->stampInvoicesSent($group);
                $this->info("Sent {$name} free-delivery summary to {$email} ({$group->count()} invoices).");
            } catch (Throwable $e) {
                Log::warning('delivery_bill.company_digest_failed', ['marketplace_id' => $marketplaceId, 'error' => $e->getMessage()]);
                $this->warn("{$name} digest email failed: ".$e->getMessage());
            }
        }
    }
}
