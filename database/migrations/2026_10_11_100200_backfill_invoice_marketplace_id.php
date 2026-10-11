<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Setting\Models\Marketplace;
use Modules\Shipment\Models\Shipment;

/**
 * Freeze the marketplace on existing company (marketplace free-delivery)
 * invoices, so a later store move between marketplaces does not move the bill.
 * Only fills NULLs; never overwrites.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('invoices', 'marketplace_id') || ! Schema::hasColumn('invoices', 'payer_type')) {
            return;
        }

        DB::table('invoices')
            ->where('payer_type', 'company')
            ->whereNull('marketplace_id')
            ->whereNotNull('shipment_id')
            ->orderBy('id')
            ->select(['id', 'shipment_id'])
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $shipment = Shipment::query()->with('merchant')->find($row->shipment_id);
                    if (! $shipment) {
                        continue;
                    }
                    $marketplace = Marketplace::resolveForShipment($shipment, $shipment->merchant);
                    if ($marketplace) {
                        DB::table('invoices')->where('id', $row->id)->update(['marketplace_id' => $marketplace->id]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Data backfill only; nothing to undo.
    }
};
