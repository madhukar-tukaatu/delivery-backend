<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\CourierStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Closes dispatch manifest items that stayed "sent/dispatched/in_transit" although
 * the parcel was received at the manifest's destination branch.
 *
 * Before the fix, receiving a transfer at its FINAL destination did not update
 * the manifest item. Only parcels with a recorded receipt tracking event at the
 * manifest's to_branch (after the manifest was dispatched) are changed; times and
 * users come from that event. Nothing is invented.
 *
 *   php artisan transfers:repair-manifest-receipts --dry-run
 *   php artisan transfers:repair-manifest-receipts
 */
final class RepairTransferManifestReceipts extends Command
{
    protected $signature = 'transfers:repair-manifest-receipts {--dry-run : Only report what would change}';

    protected $description = 'Mark dispatch manifest items received when a receipt tracking event exists at the manifest destination';

    private const OPEN_ITEM = ['sent', 'dispatched', 'in_transit'];

    private const RECEIPT_STATUSES = [
        CourierStatus::RECEIVED_AT_TRANSIT_HUB,
        CourierStatus::RECEIVED_AT_DESTINATION_BRANCH,
        CourierStatus::RECEIVED_AT_DESTINATION_SUB_BRANCH,
    ];

    public function handle(): int
    {
        if (! Schema::hasTable('dispatch_manifest_items') || ! Schema::hasTable('dispatch_manifests')) {
            $this->warn('Dispatch manifest tables not found.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');

        $items = DB::table('dispatch_manifest_items as dmi')
            ->join('dispatch_manifests as dm', 'dm.id', '=', 'dmi.dispatch_manifest_id')
            ->whereIn('dmi.status', self::OPEN_ITEM)
            ->get([
                'dmi.id as item_id', 'dmi.shipment_id', 'dmi.status as item_status',
                'dm.id as manifest_id', 'dm.manifest_number', 'dm.to_branch_id',
                'dm.dispatched_at', 'dm.created_at',
            ]);

        $fixedItems = 0;
        $touched = [];
        $rows = [];

        foreach ($items as $item) {
            $event = $this->receiptEvent((int) $item->shipment_id, (int) $item->to_branch_id, $item->dispatched_at ?? $item->created_at);
            if (! $event) {
                continue;
            }

            $rows[] = [
                $item->manifest_number ?? ('#'.$item->manifest_id),
                $item->shipment_id,
                $item->item_status.' -> received',
                $event->status,
                (string) $event->created_at,
            ];

            if (! $dry) {
                DB::table('dispatch_manifest_items')->where('id', $item->item_id)
                    ->update(['status' => 'received', 'updated_at' => now()]);
            }

            $fixedItems++;
            $prev = $touched[$item->manifest_id] ?? null;
            if (! $prev || strtotime((string) $event->created_at) > strtotime((string) $prev['at'])) {
                $touched[$item->manifest_id] = ['at' => $event->created_at, 'by' => $event->created_by];
            }
        }

        if ($rows !== []) {
            $this->table(['Manifest', 'Shipment', 'Item', 'Receipt event', 'Event time'], $rows);
        }

        $closed = 0;
        foreach ($touched as $manifestId => $last) {
            $manifest = DB::table('dispatch_manifests')->where('id', $manifestId)
                ->first(['status', 'to_branch_id', 'dispatched_at', 'created_at']);
            if (! $manifest || $manifest->status === 'received') {
                continue;
            }

            // Leave the manifest open while any parcel on it has no recorded receipt.
            $since = $manifest->dispatched_at ?? $manifest->created_at;
            $openItems = DB::table('dispatch_manifest_items')
                ->where('dispatch_manifest_id', $manifestId)
                ->whereIn('status', self::OPEN_ITEM)
                ->pluck('shipment_id');
            $stillOpen = $openItems->contains(fn ($sid) => ! $this->receiptEvent((int) $sid, (int) $manifest->to_branch_id, $since));
            if ($stillOpen) {
                continue;
            }

            if (! $dry) {
                DB::table('dispatch_manifests')->where('id', $manifestId)->update([
                    'status' => 'received',
                    'received_at' => $last['at'],
                    'received_by' => $last['by'],
                    'updated_at' => now(),
                ]);
            }
            $closed++;
        }

        $this->info(sprintf(
            '%s %d manifest item(s); %s %d manifest(s).',
            $dry ? '[dry-run] Would mark received:' : 'Marked received:',
            $fixedItems,
            $dry ? 'would close' : 'closed',
            $closed
        ));

        return self::SUCCESS;
    }

    private function receiptEvent(int $shipmentId, int $branchId, $since): ?object
    {
        return DB::table('tracking_events')
            ->where('shipment_id', $shipmentId)
            ->where('branch_id', $branchId)
            ->whereIn('status', self::RECEIPT_STATUSES)
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->orderBy('created_at')
            ->orderBy('id')
            ->first(['id', 'status', 'created_by', 'created_at']);
    }
}