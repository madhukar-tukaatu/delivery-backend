<?php

namespace Modules\Billing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\MerchantDeliveryBillingService;
use Modules\Merchant\Models\Merchant;
use Modules\Notification\Models\EmailLog;
use Throwable;

class SendMerchantBillDigest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $merchantId,
        public string $period = 'daily', // 'daily' or 'weekly'
    ) {
    }

    public function handle(MerchantDeliveryBillingService $billingService): void
    {
        $merchant = Merchant::query()->find($this->merchantId);
        if (! $merchant) {
            Log::warning('merchant_bill_digest.merchant_not_found', ['merchant_id' => $this->merchantId]);
            return;
        }

        $email = $merchant->email ?? $merchant->contact_email ?? null;
        if (! $email) {
            Log::info('merchant_bill_digest.no_email', ['merchant_id' => $this->merchantId]);
            return;
        }

        // Determine date range
        $toDate = now()->toDateString();
        $fromDate = $this->period === 'weekly' 
            ? now()->subWeek()->toDateString() 
            : now()->subDay()->toDateString();

        $invoices = $billingService->getUnpaidInvoicesForMerchant($this->merchantId, $fromDate, $toDate);

        if ($invoices->isEmpty()) {
            Log::info('merchant_bill_digest.no_unpaid_invoices', ['merchant_id' => $this->merchantId]);
            return;
        }

        $subject = $this->period === 'weekly' 
            ? 'Weekly Delivery Charges Summary' 
            : 'Daily Delivery Charges Summary';

        $body = $billingService->buildDigestEmailBody($invoices);

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

            $billingService->stampInvoicesSent($invoices);

            Log::info('merchant_bill_digest.sent', [
                'merchant_id' => $this->merchantId,
                'period' => $this->period,
                'invoice_count' => $invoices->count(),
                'total_amount' => $invoices->sum('total_amount'),
            ]);
        } catch (Throwable $e) {
            Log::error('merchant_bill_digest.failed', [
                'merchant_id' => $this->merchantId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}