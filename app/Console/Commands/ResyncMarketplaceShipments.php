<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Setting\Services\MarketplaceShipmentResync;

/**
 * Example: php artisan marketplace:resync-shipments --merchant=65 --dry-run
 *          php artisan marketplace:resync-shipments --all
 */
class ResyncMarketplaceShipments extends Command
{
    protected $signature = 'marketplace:resync-shipments
        {--merchant=* : Merchant (store) id, repeatable}
        {--all : Every merchant that has a marketplace}
        {--dry-run : Only count, do not update}';

    protected $description = 'Copy each store\'s current marketplace onto its open (not delivered/cancelled/returned) shipments';

    public function handle(MarketplaceShipmentResync $resync): int
    {
        $ids = array_map('intval', (array) $this->option('merchant'));

        if ($this->option('all')) {
            $ids = DB::table('merchants')->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        if ($ids === []) {
            $this->error('Pass --merchant=<id> (repeatable) or --all.');

            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');
        $results = $resync->forMerchants($ids, $dryRun);

        $this->table(
            ['merchant_id', 'marketplace_id', $dryRun ? 'shipments_to_move' : 'shipments_moved'],
            array_map(fn ($r) => [$r['merchant_id'], $r['marketplace_id'] ?? 'none', $r['shipments']], $results)
        );

        $this->info(($dryRun ? 'Would move ' : 'Moved ').MarketplaceShipmentResync::total($results).' open shipment(s).');

        return self::SUCCESS;
    }
}
