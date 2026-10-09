<?php

namespace Modules\Dispatch\Services;

use App\Support\BranchShareCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Dispatch\Models\DispatchManifest;
use Modules\Dispatch\Models\DispatchManifestItem;

/**
 * Actual transfer hops and transport cost per dispatch manifest.
 *
 *  - recordDispatch(): one shipment_route_steps row per shipment on the manifest
 *    (from_branch -> to_branch, manifest id, transport portion, dispatched_at).
 *  - applyTransportCost(): the dispatching branch's trip cost, split over the
 *    manifest items (equal or by weight) and copied onto the hop rows.
 *  - markReceived(): closes the manifest item, the hop, and the manifest when
 *    every item is received.
 */
class ManifestHopService
{
    public const MODES = ['equal', 'weight'];

    /** Item statuses that still mean "on the truck, not yet checked in". */
    public const OPEN_ITEM_STATUSES = ['sent', 'dispatched', 'in_transit'];

    /** Manifest statuses where items can still be received. */
    public const IN_FLIGHT_MANIFEST_STATUSES = ['dispatched', 'in_transit', 'partially_received'];

    public function recordDispatch(DispatchManifest $manifest): void
    {
        if (! $this->hopColumnsReady()) {
            return;
        }

        $manifest->loadMissing('items');
        foreach ($manifest->items as $item) {
            if (($item->status ?? null) === 'cancelled' || (bool) ($item->is_extra ?? false)) {
                continue;
            }
            $this->upsertHop($manifest, $item);
        }
    }

    /**
     * Split one total transport cost over every item of the given manifests
     * (one dispatch request can create several manifests). Each manifest then
     * stores the sum of its own items.
     *
     * @param  iterable<DispatchManifest>  $manifests
     */
    public function applyTransportCost(iterable $manifests, float $total, string $mode = 'equal', ?int $userId = null, ?string $reason = null): void
    {
        if (! $this->manifestColumnsReady()) {
            return;
        }

        $mode = in_array($mode, self::MODES, true) ? $mode : 'equal';
        $manifests = collect($manifests)->filter()->values();
        if ($manifests->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($manifests, $total, $mode, $userId, $reason) {
            // Extra parcels (arrived on this TR but billed on their own TR) and
            // cancelled lines never share this trip's cost.
            $items = DispatchManifestItem::query()
                ->whereIn('dispatch_manifest_id', $manifests->pluck('id'))
                ->where('status', '!=', 'cancelled')
                ->when($this->containerColumnsReady(), fn ($q) => $q->where('is_extra', false))
                ->orderBy('id')
                ->get();

            $weights = [];
            if ($items->isNotEmpty()) {
                $shipmentWeights = DB::table('shipments')
                    ->whereIn('id', $items->pluck('shipment_id'))
                    ->get(['id', 'weight', 'chargeable_weight', 'actual_weight'])
                    ->keyBy('id');
                foreach ($items as $item) {
                    $s = $shipmentWeights->get($item->shipment_id);
                    $weights[$item->id] = $s ? (float) ($s->chargeable_weight ?: $s->weight ?: $s->actual_weight ?: 0) : 0;
                }
            }

            $alloc = BranchShareCalculator::allocateTransport($total, $weights, $mode);

            foreach ($items as $item) {
                $item->forceFill(['transport_cost' => $alloc[$item->id] ?? 0])->save();
            }

            foreach ($manifests as $manifest) {
                $manifest->refresh();
                $old = (float) ($manifest->transport_cost ?? 0);
                $sum = round((float) $items->where('dispatch_manifest_id', $manifest->id)->sum(fn ($i) => (float) ($alloc[$i->id] ?? 0)), 2);

                $log = $manifest->transport_cost_log;
                if (is_string($log)) {
                    $log = json_decode($log, true);
                }
                $log = is_array($log) ? $log : [];
                $first = $manifest->transport_cost_entered_at === null;
                $log[] = [
                    'from' => $first ? null : $old,
                    'to' => $sum,
                    'request_total' => round($total, 2),
                    'mode' => $mode,
                    'by' => $userId,
                    'at' => now()->toIso8601String(),
                    'reason' => $reason,
                ];

                $payload = [
                    'transport_cost' => $sum,
                    'transport_cost_split_mode' => $mode,
                    'transport_cost_log' => json_encode($log),
                ];
                if ($first) {
                    $payload['transport_cost_entered_by'] = $userId;
                    $payload['transport_cost_entered_at'] = now();
                } else {
                    $payload['transport_cost_updated_by'] = $userId;
                    $payload['transport_cost_updated_at'] = now();
                }
                DB::table('dispatch_manifests')->where('id', $manifest->id)->update($payload + ['updated_at' => now()]);

                $this->syncHopTransport($manifest);
            }
        });
    }

    /**
     * Manager edit of a manifest's trip cost. Blocked once any shipment on it
     * has its branch shares on a statement or settled.
     */
    public function updateTransportCost(DispatchManifest $manifest, float $total, string $mode, ?int $userId, ?string $reason = null): DispatchManifest
    {
        $shipmentIds = $manifest->items()->pluck('shipment_id')->all();

        if (Schema::hasTable('shipment_branch_shares')) {
            $locked = DB::table('shipment_branch_shares')
                ->whereIn('shipment_id', $shipmentIds)
                ->where(function ($q) {
                    $q->whereNotNull('statement_id')->orWhere('status', '!=', 'pending');
                })
                ->exists();
            if ($locked) {
                throw ValidationException::withMessages([
                    'transport_cost' => ['Shipments on this manifest are already on an inter-branch statement. The transport cost can no longer change.'],
                ]);
            }
        }

        $this->applyTransportCost([$manifest], $total, $mode, $userId, $reason ?: 'edited');

        // Recompute delivered shipments so their shares follow the new cost.
        foreach ($shipmentIds as $shipmentId) {
            try {
                $shipment = \Modules\Shipment\Models\Shipment::find($shipmentId);
                if ($shipment && strtolower((string) $shipment->status) === 'delivered') {
                    app(\Modules\Billing\Services\BranchCommissionService::class)->autoEnsureForShipment($shipment);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $manifest->fresh('items');
    }

    /**
     * Shipment received at $branchId: close the open manifest item headed
     * there, the matching hop, and the manifest when nothing is left open.
     */
    public function markReceived(int $shipmentId, int $branchId, ?int $actorId = null): void
    {
        if (! Schema::hasTable('dispatch_manifest_items')) {
            return;
        }

        $item = DispatchManifestItem::query()
            ->where('shipment_id', $shipmentId)
            ->whereIn('status', self::OPEN_ITEM_STATUSES)
            ->whereHas('manifest', function ($q) use ($branchId) {
                $q->where('to_branch_id', $branchId)
                    ->whereIn('status', self::IN_FLIGHT_MANIFEST_STATUSES);
            })
            ->latest('id')
            ->first();

        if (! $item) {
            // Still close the hop if one is open toward this branch.
            $this->closeHop($shipmentId, null, $branchId);

            return;
        }

        $itemUpdate = ['status' => 'received'];
        if ($this->itemColumnsReady()) {
            $itemUpdate['received_at'] = now();
        }
        if ($this->containerColumnsReady()) {
            $itemUpdate['received_by'] = $actorId;
            $itemUpdate['scanned_at'] = $item->scanned_at ?? now();
        }
        $item->forceFill($itemUpdate)->save();

        $this->closeHop($shipmentId, (int) $item->dispatch_manifest_id, $branchId);
        $this->closeManifestIfDone((int) $item->dispatch_manifest_id, $actorId);
    }

    public function closeManifestIfDone(int $manifestId, ?int $actorId = null): void
    {
        $this->refreshCounts($manifestId);

        $open = DispatchManifestItem::query()
            ->where('dispatch_manifest_id', $manifestId)
            ->whereIn('status', self::OPEN_ITEM_STATUSES)
            ->exists();

        if ($open) {
            return;
        }

        // Any parcel still missing / declared lost keeps the TR "partially received".
        $short = DispatchManifestItem::query()
            ->where('dispatch_manifest_id', $manifestId)
            ->whereIn('status', ['missing', 'lost'])
            ->exists();

        $manifest = DispatchManifest::query()
            ->where('id', $manifestId)
            ->whereIn('status', self::IN_FLIGHT_MANIFEST_STATUSES)
            ->first();
        if (! $manifest) {
            return;
        }

        $manifest->forceFill([
            'status' => $short ? 'partially_received' : 'received',
            'received_by' => $manifest->received_by ?? $actorId,
            'received_at' => $manifest->received_at ?? now(),
        ])->save();
    }

    /**
     * Recount expected / received / missing / extra for a TR container from its items.
     */
    public function refreshCounts(int $manifestId): void
    {
        if (! $this->containerColumnsReady()) {
            return;
        }

        $items = DB::table('dispatch_manifest_items')
            ->where('dispatch_manifest_id', $manifestId)
            ->get(['status', 'is_extra']);

        $notCancelled = $items->reject(fn ($i) => in_array($i->status, ['cancelled', 'moved'], true));
        $extra = $notCancelled->filter(fn ($i) => (bool) $i->is_extra)->count();

        DB::table('dispatch_manifests')->where('id', $manifestId)->update([
            'expected_count' => $notCancelled->count() - $extra,
            'received_count' => $notCancelled->where('status', 'received')->count(),
            'missing_count' => $notCancelled->whereIn('status', ['missing', 'lost'])->count(),
            'extra_count' => $extra,
            'updated_at' => now(),
        ]);
    }

    /** Close the hop row of one shipment on one manifest (used for extra / moved parcels). */
    public function closeHopForManifest(int $shipmentId, int $manifestId): void
    {
        $this->closeHop($shipmentId, $manifestId, 0);
    }

    /**
     * Actual hops for a shipment, in order. Falls back to manifest items for
     * shipments dispatched before hop recording existed.
     *
     * @return list<array{from_branch_id:?int,to_branch_id:?int,dispatch_manifest_id:?int,transport_cost:float,dispatched_at:mixed,received_at:mixed}>
     */
    public function hopsForShipment(int $shipmentId): array
    {
        $rows = collect();
        if ($this->hopColumnsReady()) {
            $rows = DB::table('shipment_route_steps')
                ->where('shipment_id', $shipmentId)
                ->whereNotNull('dispatch_manifest_id')
                ->orderBy('sequence')
                ->orderBy('id')
                ->get(['from_branch_id', 'to_branch_id', 'dispatch_manifest_id', 'transport_cost', 'dispatched_at', 'received_at']);
            // Manifests cancelled before dispatch never moved the parcel.
            if ($rows->isNotEmpty()) {
                $cancelled = DB::table('dispatch_manifests')
                    ->whereIn('id', $rows->pluck('dispatch_manifest_id')->filter()->unique())
                    ->where('status', 'cancelled')
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
                $rows = $rows->reject(fn ($r) => in_array((int) $r->dispatch_manifest_id, $cancelled, true))->values();
            }
        }

        if ($rows->isEmpty() && Schema::hasTable('dispatch_manifest_items')) {
            $select = ['dm.from_branch_id', 'dm.to_branch_id', 'dm.id as dispatch_manifest_id', 'dm.dispatched_at', 'dm.received_at'];
            if ($this->itemColumnsReady()) {
                $select[] = 'dmi.transport_cost';
            }
            $rows = DB::table('dispatch_manifest_items as dmi')
                ->join('dispatch_manifests as dm', 'dm.id', '=', 'dmi.dispatch_manifest_id')
                ->where('dmi.shipment_id', $shipmentId)
                ->whereNotIn('dmi.status', ['missing', 'cancelled', 'moved', 'added'])
                ->where('dm.status', '!=', 'cancelled')
                ->orderBy('dm.dispatched_at')
                ->orderBy('dm.id')
                ->get($select);
        }

        $manifestIds = $rows->pluck('dispatch_manifest_id')->filter()->unique()->values();
        $manifests = $manifestIds->isEmpty() ? collect() : DB::table('dispatch_manifests')
            ->whereIn('id', $manifestIds)
            ->get(array_values(array_filter([
                'id', 'manifest_number',
                $this->containerColumnsReady() ? 'transfer_number' : null,
                $this->containerColumnsReady() ? 'vehicle_type' : null,
                'vehicle_number', 'driver_name', 'status',
            ])))
            ->keyBy('id');
        $branchNames = DB::table('branches')
            ->whereIn('id', $rows->pluck('from_branch_id')->merge($rows->pluck('to_branch_id'))->filter()->unique())
            ->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'transfer_number' => $manifests->get($r->dispatch_manifest_id)?->transfer_number ?? null,
            'manifest_number' => $manifests->get($r->dispatch_manifest_id)?->manifest_number ?? null,
            'manifest_status' => $manifests->get($r->dispatch_manifest_id)?->status ?? null,
            'vehicle_type' => $manifests->get($r->dispatch_manifest_id)?->vehicle_type ?? null,
            'vehicle_number' => $manifests->get($r->dispatch_manifest_id)?->vehicle_number ?? null,
            'from_branch_name' => $r->from_branch_id ? ($branchNames[$r->from_branch_id] ?? null) : null,
            'to_branch_name' => $r->to_branch_id ? ($branchNames[$r->to_branch_id] ?? null) : null,
            'from_branch_id' => $r->from_branch_id ? (int) $r->from_branch_id : null,
            'to_branch_id' => $r->to_branch_id ? (int) $r->to_branch_id : null,
            'dispatch_manifest_id' => $r->dispatch_manifest_id ? (int) $r->dispatch_manifest_id : null,
            'transport_cost' => round((float) ($r->transport_cost ?? 0), 2),
            'dispatched_at' => $r->dispatched_at,
            'received_at' => $r->received_at,
        ])->values()->all();
    }

    private function upsertHop(DispatchManifest $manifest, DispatchManifestItem $item): void
    {
        $existing = DB::table('shipment_route_steps')
            ->where('shipment_id', $item->shipment_id)
            ->where('dispatch_manifest_id', $manifest->id)
            ->first();

        $payload = [
            'from_branch_id' => $manifest->from_branch_id,
            'to_branch_id' => $manifest->to_branch_id,
            'transport_cost' => round((float) ($item->transport_cost ?? 0), 2),
            'dispatched_at' => $manifest->dispatched_at ?? now(),
            'departed_at' => $manifest->dispatched_at ?? now(),
            'status' => 'dispatched',
            'updated_at' => now(),
        ];

        // Backfilled hops for legs that were already received keep that state.
        $itemReceived = ($item->status ?? null) === 'received' || ! empty($item->received_at);
        if ($existing && ! empty($existing->received_at)) {
            unset($payload['status']);
        } elseif ($itemReceived) {
            $payload['status'] = 'received';
            $payload['received_at'] = $item->received_at ?? $manifest->received_at ?? now();
        }

        if ($existing) {
            DB::table('shipment_route_steps')->where('id', $existing->id)->update($payload);

            return;
        }

        $sequence = (int) DB::table('shipment_route_steps')
            ->where('shipment_id', $item->shipment_id)
            ->whereNotNull('dispatch_manifest_id')
            ->max('sequence');

        DB::table('shipment_route_steps')->insert($payload + [
            'shipment_id' => $item->shipment_id,
            'dispatch_manifest_id' => $manifest->id,
            'sequence' => $sequence + 1,
            'created_at' => now(),
        ]);
    }

    private function syncHopTransport(DispatchManifest $manifest): void
    {
        if (! $this->hopColumnsReady()) {
            return;
        }

        $items = DispatchManifestItem::query()
            ->where('dispatch_manifest_id', $manifest->id)
            ->where('status', '!=', 'cancelled')
            ->when($this->containerColumnsReady(), fn ($q) => $q->where('is_extra', false))
            ->get();
        foreach ($items as $item) {
            $updated = DB::table('shipment_route_steps')
                ->where('shipment_id', $item->shipment_id)
                ->where('dispatch_manifest_id', $manifest->id)
                ->update(['transport_cost' => round((float) $item->transport_cost, 2), 'updated_at' => now()]);
            if ($updated === 0) {
                $this->upsertHop($manifest->fresh(), $item);
            }
        }
    }

    private function closeHop(int $shipmentId, ?int $manifestId, int $branchId): void
    {
        if (! $this->hopColumnsReady()) {
            return;
        }

        $q = DB::table('shipment_route_steps')
            ->where('shipment_id', $shipmentId)
            ->whereNotNull('dispatch_manifest_id')
            ->whereNull('received_at');

        if ($manifestId) {
            $q->where('dispatch_manifest_id', $manifestId);
        } else {
            $q->where('to_branch_id', $branchId);
        }

        try {
            $q->update(['received_at' => now(), 'status' => 'received', 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('manifest_hop.close_failed', ['shipment_id' => $shipmentId, 'error' => $e->getMessage()]);
        }
    }

    private function hopColumnsReady(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('shipment_route_steps')
            && Schema::hasColumn('shipment_route_steps', 'dispatch_manifest_id');
    }

    private function manifestColumnsReady(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasColumn('dispatch_manifests', 'transport_cost')
            && $this->itemColumnsReady();
    }

    public function containerColumnsReady(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasColumn('dispatch_manifests', 'transfer_number')
            && Schema::hasColumn('dispatch_manifest_items', 'is_extra');
    }

    private function itemColumnsReady(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasColumn('dispatch_manifest_items', 'transport_cost');
    }
}
