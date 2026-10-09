<?php

namespace Modules\Billing\Console\Commands;

use Illuminate\Console\Command;
use Modules\Billing\Services\BranchCommissionService;
use Modules\Billing\Services\InterBranchStatementService;
use Modules\Shipment\Models\Shipment;

class GenerateBranchSettlements extends Command
{
    protected $signature = 'branch-settlements:generate
        {--period=weekly : daily|weekly}
        {--from= : Period start (Y-m-d), overrides --period}
        {--to= : Period end (Y-m-d), overrides --period}
        {--backfill : First compute branch shares for delivered shipments in the period that have none}';

    protected $description = 'Put paid transfer delivery shares on collecting-branch -> branch statements';

    public function handle(InterBranchStatementService $statements): int
    {
        $period = (string) $this->option('period');
        if (! in_array($period, ['daily', 'weekly'], true)) {
            $this->error('Period must be daily or weekly');

            return self::FAILURE;
        }

        [$start, $end] = $statements->periodBounds($period, $this->option('from'), $this->option('to'));

        if ($this->option('backfill')) {
            $count = 0;
            Shipment::query()
                ->where('status', 'delivered')
                ->whereNull('branch_share_status')
                ->whereBetween('delivered_at', [$start->startOfDay(), $end->endOfDay()])
                ->orderBy('id')
                ->chunkById(200, function ($chunk) use (&$count) {
                    foreach ($chunk as $shipment) {
                        try {
                            app(BranchCommissionService::class)->autoEnsureForShipment($shipment);
                            $count++;
                        } catch (\Throwable $e) {
                            report($e);
                        }
                    }
                });
            $this->info("Backfilled branch shares for {$count} shipment(s).");
        }

        $result = $statements->generate($start, $end);

        $this->info(sprintf(
            'Period %s to %s: %d line(s) on %d statement(s), %d share(s) kept by the collecting branch.',
            $start->toDateString(),
            $end->toDateString(),
            $result['lines'],
            count($result['statements']),
            $result['kept_by_collector'],
        ));

        return self::SUCCESS;
    }
}
