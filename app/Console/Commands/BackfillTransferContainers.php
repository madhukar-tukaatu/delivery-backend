<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\CourierStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Dispatch\Services\ManifestHopService;

/**
 * One-time backfill for TR transfer containers on existing dispatch manifests.
 *
 *  1. Assigns TR-000001, TR-000002, ... to manifests without a TR number, in
 *     dispatch date order. manifest_number (MF-...) is kept.
 *  2. Closes items still "sent" when a receipt tracking event exists at the
 *     manifest's to_branch after dispatch (time / user taken from the event).
 *  3. Fills expected / received / missing / extra counts from the items.
 *  4. Maps the manifest status: received, partially_received (missing items)
 *     or dispatched (parcels still in transit).
 *  5. Reports (never changes) stuck or mis-received parcels for manual review.
 *
 * Transport costs and statement-locked branch shares are never touched.
 * Single-parcel containers stay single (they already travelled separately).
 *
 *   php artisan transfers:backfill-tr --dry-run
 *   php artisan transfers:backfill-tr
 */
final class BackfillTransferContainers extends Command
{
    protected $signature = 'transfers:backfill-tr {--dry-run : Only report what would change}';

    protected $description = 'Assign TR numbers, counts and statuses to existing transfer manifests and report stuck parcels';

    private const OPEN_ITEM = ['sent', 'dispatched', 'in_transit'];

    private const RECEIPT_STATUSES = [
        CourierStatus::RECEIVED_AT_TRANSIT_HUB,
        CourierStatus::RECEIVED_AT_DESTINATION_BRANCH,
        CourierStatus::RECEIVED_AT_DESTINATION_SUB_BRANCH,
    ];

    private const IN_TRANSIT = [CourierStatus::IN_TRANSIT, CourierStatus::DISPATCHED_TO_DESTINATION_BRANCH];

    public function handle(ManifestHopService $hops): int
    {
        if (! Schema::hasColumn('dispatch_manifests', 'transfer_number')) {
            $this->error('Run php artisan migrate first (transfer_number column missing).');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $prefix = $dry ? '[dry-run] ' : '';

        $manifests = DB::table('dispatch_manifests')
            ->orderByRaw('COALESCE(dispatched_at, created_at)')
            ->orderBy('id')
            ->get();

        if ($manifests->isEmpty()) {
            $this->info('No dispatch manifests found. Nothing to do.');

            return self::SUCCESS;
        }

        $branchNames = DB::table('branches')->pluck('name', 'id');
        $bn = fn ($id) => $id ? ($branchNames[$id] ?? "#{$id}") : '-';

        // 1. TR numbers --------------------------------------------------
        $last = DB::table('dispatch_manifests')
            ->where('transfer_number', 'like', 'TR-%')
            ->orderByRaw('LENGTH(transfer_number) DESC')
            ->orderByDesc('transfer_number')
            ->value('transfer_number');
        $next = (is_string($last) && preg_match('/(\d+)$/', $last, $m) ? (int) $m[1] : 0) + 1;

        $numbered = [];
        foreach ($manifests as $mf) {
            if (! empty($mf->transfer_number)) {
                continue;
            }
            do {
                $tr = 'TR-'.str_pad((string) $next++, 6, '0', STR_PAD_LEFT);
            } while (DB::table('dispatch_manifests')->where('transfer_number', $tr)->exists());
            $mf->transfer_number = $tr;
            $numbered[] = [$mf->id, $mf->manifest_number, $tr, $bn($mf->from_branch_id).' -> '.$bn($mf->to_branch_id), $mf->status, (string) ($mf->dispatched_at ?? $mf->created_at)];
            if (! $dry) {
                DB::table('dispatch_manifests')->where('id', $mf->id)->update(['transfer_number' => $tr, 'updated_at' => now()]);
            }
        }
        $this->line('');
        $this->info($prefix.'TR numbers: '.count($numbered).' manifest(s)');
        if ($numbered) {
            $this->table(['ID', 'Manifest', 'TR', 'Trip', 'Status', 'Dispatched'], $numbered);
        }

        // 2. Close items received per tracking evidence -------------------
        $closedItems = [];
        $items = DB::table('dispatch_manifest_items as dmi')
            ->join('dispatch_manifests as dm', 'dm.id', '=', 'dmi.dispatch_manifest_id')
            ->join('shipments as s', 's.id', '=', 'dmi.shipment_id')
            ->whereIn('dmi.status', self::OPEN_ITEM)
            ->get(['dmi.id as item_id', 'dmi.shipment_id', 'dmi.status as item_status', 'dm.id as manifest_id', 'dm.to_branch_id', 'dm.dispatched_at', 'dm.created_at', 's.tracking_number', 's.status as shipment_status']);
        $evidence = [];
        foreach ($items as $it) {
            $event = $this->receiptEvent((int) $it->shipment_id, (int) $it->to_branch_id, $it->dispatched_at ?? $it->created_at);
            if (! $event) {
                continue;
            }
            $evidence[$it->item_id] = $event;
            $closedItems[] = [$this->trOf($manifests, $it->manifest_id), $it->tracking_number, $it->item_status.' -> received', $event->status.' @ '.$bn($it->to_branch_id), (string) $event->created_at];
            if (! $dry) {
                $update = ['status' => 'received', 'received_at' => $event->created_at, 'updated_at' => now()];
                if (Schema::hasColumn('dispatch_manifest_items', 'received_by')) {
                    $update['received_by'] = $event->created_by;
                }
                DB::table('dispatch_manifest_items')->where('id', $it->item_id)->update($update);
                $this->closeHop((int) $it->shipment_id, (int) $it->manifest_id, $event->created_at);
            }
        }
        $this->line('');
        $this->info($prefix.'Items closed from receipt events: '.count($closedItems));
        if ($closedItems) {
            $this->table(['TR', 'Shipment', 'Item', 'Evidence', 'At'], $closedItems);
        }

        // 3 + 4. Counts and status ------------------------------------------
        $statusRows = [];
        foreach ($manifests as $mf) {
            $its = DB::table('dispatch_manifest_items')->where('dispatch_manifest_id', $mf->id)->get(['id', 'status', 'received_at']);
            $statuses = $its->map(fn ($i) => isset($evidence[$i->id]) ? 'received' : $i->status);
            $open = $statuses->filter(fn ($s) => in_array($s, self::OPEN_ITEM, true))->count();
            $missing = $statuses->filter(fn ($s) => in_array($s, ['missing', 'lost'], true))->count();
            $received = $statuses->filter(fn ($s) => $s === 'received')->count();
            $active = $statuses->reject(fn ($s) => in_array($s, ['cancelled', 'moved'], true))->count();

            $newStatus = $mf->status;
            if (in_array($mf->status, ['dispatched', 'in_transit', 'received', 'partially_received'], true)) {
                $newStatus = $open > 0 ? 'dispatched' : ($missing > 0 ? 'partially_received' : 'received');
            }

            $receivedAt = $mf->received_at;
            if (in_array($newStatus, ['received', 'partially_received'], true) && ! $receivedAt) {
                $receivedAt = collect($evidence)->only($its->pluck('id')->all())->max('created_at')
                    ?? $its->max('received_at');
            }

            $changed = $newStatus !== $mf->status;
            $statusRows[] = [
                $mf->transfer_number ?? $this->trOf($manifests, $mf->id),
                $bn($mf->from_branch_id).' -> '.$bn($mf->to_branch_id),
                $mf->status.($changed ? ' -> '.$newStatus : ''),
                "{$active} / {$received} / {$missing} / {$open}",
                $active === 1 ? 'single parcel (kept as is)' : '',
            ];

            if (! $dry) {
                DB::table('dispatch_manifests')->where('id', $mf->id)->update(array_filter([
                    'status' => $newStatus,
                    'received_at' => $receivedAt,
                    'updated_at' => now(),
                ], fn ($v) => $v !== null));
                $hops->refreshCounts((int) $mf->id);
            }
        }
        $this->line('');
        $this->info($prefix.'Counts and status (expected / received / missing / still in transit):');
        $this->table(['TR', 'Trip', 'Status', 'Counts', 'Note'], $statusRows);

        // 5. Report stuck / mis-received parcels (no changes) ------------------
        $report = [];
        $stillOpen = DB::table('dispatch_manifest_items as dmi')
            ->join('dispatch_manifests as dm', 'dm.id', '=', 'dmi.dispatch_manifest_id')
            ->join('shipments as s', 's.id', '=', 'dmi.shipment_id')
            ->whereIn('dmi.status', self::OPEN_ITEM)
            ->get(['dmi.id as item_id', 'dm.id as manifest_id', 'dm.to_branch_id', 's.tracking_number', 's.status', 's.current_branch_id', 's.destination_branch_id']);
        foreach ($stillOpen as $r) {
            if (isset($evidence[$r->item_id])) {
                continue;
            }
            if (! in_array($r->status, self::IN_TRANSIT, true)) {
                $report[] = [
                    $this->trOf($manifests, $r->manifest_id), $r->tracking_number,
                    'TR line still "sent" but parcel is '.$r->status.' at '.$bn($r->current_branch_id),
                    'Check the parcel; receive the TR at '.$bn($r->to_branch_id).' (line closes as already received).',
                ];
            }
        }

        // Old Dispatches > Receive marked parcels "received at destination" even at a transit hub.
        $misreceived = DB::table('dispatch_manifest_items as dmi')
            ->join('dispatch_manifests as dm', 'dm.id', '=', 'dmi.dispatch_manifest_id')
            ->join('shipments as s', 's.id', '=', 'dmi.shipment_id')
            ->where('dmi.status', 'received')
            ->whereColumn('s.current_branch_id', 'dm.to_branch_id')
            ->whereColumn('s.destination_branch_id', '!=', 'dm.to_branch_id')
            ->whereIn('s.status', [CourierStatus::RECEIVED_AT_DESTINATION_BRANCH, CourierStatus::RECEIVED_AT_DESTINATION_SUB_BRANCH])
            ->get(['dm.id as manifest_id', 's.tracking_number', 's.status', 's.current_branch_id', 's.destination_branch_id']);
        foreach ($misreceived as $r) {
            $report[] = [
                $this->trOf($manifests, $r->manifest_id), $r->tracking_number,
                'Marked '.$r->status.' at '.$bn($r->current_branch_id).' but final destination is '.$bn($r->destination_branch_id),
                'Sort it for transfer at '.$bn($r->current_branch_id).' so it joins the next TR.',
            ];
        }

        $missingRows = DB::table('dispatch_manifest_items as dmi')
            ->join('dispatch_manifests as dm', 'dm.id', '=', 'dmi.dispatch_manifest_id')
            ->join('shipments as s', 's.id', '=', 'dmi.shipment_id')
            ->where('dmi.status', 'missing')
            ->get(['dm.id as manifest_id', 's.tracking_number', 's.status', 's.current_branch_id']);
        foreach ($missingRows as $r) {
            $report[] = [
                $this->trOf($manifests, $r->manifest_id), $r->tracking_number,
                'Flagged missing; parcel is '.$r->status.($r->current_branch_id ? ' at '.$bn($r->current_branch_id) : ''),
                'Resolve on the TR (found / lost).',
            ];
        }

        $this->line('');
        $this->info('Needs review (not changed): '.count($report));
        if ($report) {
            $this->table(['TR', 'Shipment', 'Issue', 'Suggested action'], $report);
        }

        $this->line('');
        $this->info(sprintf(
            '%sDone. %d TR number(s), %d item(s) closed from evidence, %d manifest(s) checked, %d parcel(s) to review. Transport costs untouched.',
            $prefix, count($numbered), count($closedItems), $manifests->count(), count($report)
        ));

        return self::SUCCESS;
    }

    private function trOf($manifests, $id): string
    {
        $m = $manifests->firstWhere('id', $id);

        return $m ? (string) ($m->transfer_number ?: $m->manifest_number) : '#'.$id;
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

    private function closeHop(int $shipmentId, int $manifestId, $at): void
    {
        if (! Schema::hasColumn('shipment_route_steps', 'dispatch_manifest_id')) {
            return;
        }
        DB::table('shipment_route_steps')
            ->where('shipment_id', $shipmentId)
            ->where('dispatch_manifest_id', $manifestId)
            ->whereNull('received_at')
            ->update(['received_at' => $at, 'status' => 'received', 'updated_at' => now()]);
    }
}
