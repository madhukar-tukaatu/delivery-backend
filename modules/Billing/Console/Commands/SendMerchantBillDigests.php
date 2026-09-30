<?php

namespace Modules\Billing\Console\Commands;

use Illuminate\Console\Command;
use Modules\Billing\Jobs\SendMerchantBillDigest;
use Modules\Merchant\Models\Merchant;

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

        // Only send weekly on Mondays, daily every day
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
        return 0;
    }
}