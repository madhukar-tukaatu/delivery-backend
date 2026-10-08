<?php

namespace Modules\Setting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Copies a store's current marketplace (merchants.marketplace_id) onto its
 * open shipments (shipments.marketplace_id). Delivered / cancelled / returned
 * shipments keep their historical marketplace.
 */
class MarketplaceShipmentResync
{
    public const CLOSED_STATUSES = [
        'delivered',
        'cancelled',
        'returned',
        'returned_to_merchant',
        'return_delivered',
        'rto_delivered',
    ];

    /**
     * @param  array<int, int|string>  $merchantIds
     * @return array<int, array{merchant_id: int, marketplace_id: ?int, shipments: int}>
     */
    public function forMerchants(array $merchantIds, bool $dryRun = false): array
    {
        if (! Schema::hasTable('shipments') || ! Schema::hasColumn('shipments', 'marketplace_id')) {
            return [];
        }

        $merchantIds = array_values(array_unique(array_filter(array_map('intval', $merchantIds))));
        if ($merchantIds === []) {
            return [];
        }

        $results = [];

        $merchants = DB::table('merchants')->whereIn('id', $merchantIds)->get(['id', 'marketplace_id']);

        foreach ($merchants as $merchant) {
            $marketplaceId = $merchant->marketplace_id !== null ? (int) $merchant->marketplace_id : null;

            $query = DB::table('shipments')
                ->where('merchant_id', $merchant->id)
                ->where(function ($q) {
                    $q->whereNull('status')->orWhereNotIn('status', self::CLOSED_STATUSES);
                });

            if ($marketplaceId === null) {
                $query->whereNotNull('marketplace_id');
            } else {
                $query->where(function ($q) use ($marketplaceId) {
                    $q->whereNull('marketplace_id')->orWhere('marketplace_id', '!=', $marketplaceId);
                });
            }

            $count = $dryRun ? (clone $query)->count() : $query->update(['marketplace_id' => $marketplaceId]);

            if ($count > 0 && ! $dryRun) {
                Log::info('Marketplace: open shipments moved to the store marketplace', [
                    'merchant_id' => (int) $merchant->id,
                    'marketplace_id' => $marketplaceId,
                    'shipments' => $count,
                ]);
            }

            $results[] = [
                'merchant_id' => (int) $merchant->id,
                'marketplace_id' => $marketplaceId,
                'shipments' => (int) $count,
            ];
        }

        return $results;
    }

    /**
     * @param  array<int, array{shipments: int}>  $results
     */
    public static function total(array $results): int
    {
        return array_sum(array_map(fn ($r) => (int) ($r['shipments'] ?? 0), $results));
    }
}
