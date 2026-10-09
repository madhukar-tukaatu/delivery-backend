<?php

declare(strict_types=1);

namespace Modules\Dispatch\Services;

use App\Support\CourierStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Branch\Models\Branch;
use Modules\Dispatch\Models\DispatchManifest;
use Modules\Dispatch\Models\DispatchManifestItem;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Services\ShipmentSortingService;
use Modules\Shipment\Services\TransferRouteProgressService;
use Modules\Shipment\Services\TransferService;
use Modules\Tracking\Services\TrackingService;

/**
 * TR transfer containers.
 *
 * One container (dispatch_manifests row, numbered TR-000001) per trip from a
 * branch to its NEXT hop. Every parcel whose next hop is that branch rides in
 * it, whatever its final destination: KTM -> Butwal carries Butwal last-mile
 * parcels and Butwal -> Dhangadi onward parcels together.
 *
 *  - dispatch():  sorted_for_transfer parcels -> one TR per (from, next hop),
 *                 vehicle / rider / cost recorded, cost split per parcel.
 *  - receive():   the next hop checks the TR in (scan / tick). Final-here
 *                 parcels go to last mile, onward parcels are sorted for the
 *                 next TR. Unscanned parcels are flagged missing, unexpected
 *                 parcels that belong here are added as extras.
 *  - resolveItem(): a missing parcel is later found (received) or lost.
 *  - cancel():    only an open (not yet dispatched) TR.
 */
final class TransferContainerService
{
    public const OPEN_ITEM_STATUSES = ManifestHopService::OPEN_ITEM_STATUSES;

    public const RECEIVABLE_STATUSES = ['dispatched', 'in_transit', 'partially_received'];

    private const IN_TRANSIT_SHIPMENT_STATUSES = [
        CourierStatus::IN_TRANSIT,
        CourierStatus::DISPATCHED_TO_DESTINATION_BRANCH,
    ];

    public function __construct(
        private readonly ManifestHopService $hops,
        private readonly TransferRouteProgressService $progress,
    ) {
    }

    /* ------------------------------------------------------------------ */
    /* Numbering                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Next sequential TR number (TR-000001, TR-000002, ...). Same pattern as
     * PR numbers: highest existing numeric suffix + 1, skipping any taken
     * value. The unique index is the final guarantee (see createContainer()).
     */
    public function nextTransferNumber(): string
    {
        // Zero-padded, so the longest then lexically highest value is the max.
        $last = DB::table('dispatch_manifests')
            ->where('transfer_number', 'like', 'TR-%')
            ->orderByRaw('LENGTH(transfer_number) DESC')
            ->orderByDesc('transfer_number')
            ->lockForUpdate()
            ->value('transfer_number');
        $highest = (is_string($last) && preg_match('/(\d+)$/', $last, $m)) ? (int) $m[1] : 0;

        $number = $highest + 1;
        do {
            $candidate = 'TR-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
            $exists = DB::table('dispatch_manifests')->where('transfer_number', $candidate)->exists();
            $number++;
        } while ($exists);

        return $candidate;
    }

    /* ------------------------------------------------------------------ */
    /* Dispatch                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Put parcels on TR containers, one per (from branch, next hop).
     *
     * @param  iterable<Shipment>  $shipments
     * @param  int|null  $forcedNextHopId  when set, every parcel must have this next hop (skip-hop guard)
     * @param  int  $scopeBranchId  dispatching branch (0 = HQ: use each parcel's current branch)
     * @param  array{vehicle_type?:?string,vehicle_number?:?string,rider_user_id?:?int,driver_name?:?string,driver_phone?:?string,seal_number?:?string,notes?:?string,transport_cost?:?float,transport_cost_split_mode?:?string,hold?:bool,transfer_route_id?:?int}  $meta
     * @return array{manifests: list<DispatchManifest>, skipped: array<int,string>}
     */
    public function dispatch(iterable $shipments, ?int $forcedNextHopId, int $scopeBranchId, array $meta, ?int $actorId): array
    {
        $hold = (bool) ($meta['hold'] ?? false);
        $this->validateMeta($meta);

        $groups = [];
        $skipped = [];
        $forcedNextHopId = $forcedNextHopId ? ($this->progress->resolveOperationalBranchId($forcedNextHopId) ?? $forcedNextHopId) : null;

        foreach ($shipments as $shipment) {
            $from = $scopeBranchId !== 0
                ? $scopeBranchId
                : (int) ($this->progress->resolveOperationalBranchId(
                    (int) ($shipment->current_branch_id ?: $shipment->origin_branch_id ?: 0)
                ) ?? 0);

            if ($from <= 0) {
                $skipped[(int) $shipment->id] = 'Dispatching branch could not be resolved.';
                continue;
            }

            // A route chosen on the board is assigned when the parcel has none yet.
            if (! empty($meta['transfer_route_id']) && empty($shipment->transfer_route_id)
                && Schema::hasColumn('shipments', 'transfer_route_id')) {
                $shipment->forceFill(['transfer_route_id' => (int) $meta['transfer_route_id']])->save();
            }

            $p = $this->progress->resolveForShipment($shipment, $from);
            $hop = (int) ($p['next_hop_branch_id'] ?? 0);

            try {
                if ($forcedNextHopId) {
                    $this->progress->assertNextHopMatches($shipment, (int) $forcedNextHopId, $from);
                    $hop = (int) $forcedNextHopId;
                } elseif ($p['ready_for_last_mile'] ?? false) {
                    throw ValidationException::withMessages(['shipment_ids' => [
                        sprintf('Shipment %s is already at its final destination.', $shipment->tracking_number ?? $shipment->id),
                    ]]);
                } elseif ($hop <= 0) {
                    throw ValidationException::withMessages(['shipment_ids' => [
                        sprintf('No next hop for %s. Assign an active transfer route first.', $shipment->tracking_number ?? $shipment->id),
                    ]]);
                }
                $hop = (int) ($this->progress->resolveOperationalBranchId($hop) ?? $hop);

                $openSame = $this->openContainerFor($from, $hop);
                $this->progress->assertNotInActiveManifest($shipment, $openSame?->id);
            } catch (ValidationException $e) {
                $skipped[(int) $shipment->id] = collect($e->errors())->flatten()->first() ?? $e->getMessage();
                continue;
            }

            $groups[$from.'|'.$hop]['from'] = $from;
            $groups[$from.'|'.$hop]['hop'] = $hop;
            $groups[$from.'|'.$hop]['rows'][] = ['shipment' => $shipment, 'progress' => $p];
        }

        $manifests = [];
        if ($groups !== []) {
            $manifests = DB::transaction(function () use ($groups, $meta, $actorId, $hold) {
                $out = [];
                foreach ($groups as $group) {
                    $container = $this->openContainerFor($group['from'], $group['hop'], true)
                        ?? $this->createContainer($group['from'], $group['hop'], $actorId);

                    foreach ($group['rows'] as $row) {
                        DispatchManifestItem::query()->updateOrCreate(
                            ['dispatch_manifest_id' => $container->id, 'shipment_id' => $row['shipment']->id],
                            ['status' => 'added'],
                        );
                    }
                    if (! $hold) {
                        // Parcels loaded earlier on this open TR leave with it.
                        $group['rows'] = array_merge($group['rows'], $this->heldRows(
                            $container,
                            array_map(fn ($r) => (int) $r['shipment']->id, $group['rows'])
                        ));
                    }

                    $this->applyMeta($container, $meta);
                    if (! $hold) {
                        $this->sendContainer($container, $actorId, $group['rows']);
                    } else {
                        $this->hops->refreshCounts((int) $container->id);
                    }
                    $out[] = $container->fresh();
                }

                if (! $hold && array_key_exists('transport_cost', $meta) && $meta['transport_cost'] !== null) {
                    $this->hops->applyTransportCost(
                        $out,
                        (float) $meta['transport_cost'],
                        (string) ($meta['transport_cost_split_mode'] ?? 'equal'),
                        $actorId,
                        'dispatch',
                    );
                }

                return array_map(fn ($m) => $m->fresh(['items.shipment', 'fromBranch', 'toBranch', 'rider']), $out);
            });

            if (! $hold) {
                foreach ($manifests as $m) {
                    $this->notifyNextBranch($m);
                }
            }
        }

        return ['manifests' => $manifests, 'skipped' => $skipped];
    }

    /**
     * Send an open (held) TR: parcels go in transit, hop + cost recorded.
     */
    public function dispatchOpen(DispatchManifest $container, array $meta, ?int $actorId): DispatchManifest
    {
        if ($container->status !== DispatchManifest::STATUS_OPEN) {
            throw ValidationException::withMessages(['status' => ['Only an open TR can be dispatched.']]);
        }
        $this->validateMeta($meta);

        $container = DB::transaction(function () use ($container, $meta, $actorId) {
            $container = DispatchManifest::query()->lockForUpdate()->findOrFail($container->id);
            if (! $container->items()->where('status', 'added')->exists()) {
                throw ValidationException::withMessages(['shipment_ids' => ['This TR has no parcels to dispatch.']]);
            }

            $rows = $this->heldRows($container, []);
            if ($rows === []) {
                throw ValidationException::withMessages(['shipment_ids' => ['No parcel on this TR is still ready for transfer.']]);
            }

            $this->applyMeta($container, $meta);
            $this->sendContainer($container, $actorId, $rows);

            if (array_key_exists('transport_cost', $meta) && $meta['transport_cost'] !== null) {
                $this->hops->applyTransportCost([$container->fresh()], (float) $meta['transport_cost'], (string) ($meta['transport_cost_split_mode'] ?? 'equal'), $actorId, 'dispatch');
            }

            return $container->fresh(['items.shipment', 'fromBranch', 'toBranch', 'rider']);
        });

        $this->notifyNextBranch($container);

        return $container;
    }

    /** Cancel an open TR. Its parcels stay sorted for transfer at the branch. */
    public function cancel(DispatchManifest $container, ?string $reason, ?int $actorId): DispatchManifest
    {
        return DB::transaction(function () use ($container, $reason, $actorId) {
            $container = DispatchManifest::query()->lockForUpdate()->findOrFail($container->id);
            if ($container->status !== DispatchManifest::STATUS_OPEN) {
                throw ValidationException::withMessages(['status' => [
                    'Only an open TR can be cancelled. A dispatched TR is already on the road; receive it (missing parcels are flagged) instead.',
                ]]);
            }

            $container->items()->update(['status' => 'cancelled', 'updated_at' => now()]);
            $container->forceFill([
                'status' => DispatchManifest::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $actorId,
                'cancel_reason' => $reason,
            ])->save();
            $this->hops->refreshCounts((int) $container->id);

            return $container->fresh(['items.shipment', 'fromBranch', 'toBranch']);
        });
    }

    /* ------------------------------------------------------------------ */
    /* Receive                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Check a TR in at its next hop.
     *
     * @param  list<int|string>  $scanned  shipment ids or tracking numbers that physically arrived
     * @return array{container: DispatchManifest, received: list<array>, missing: list<array>, extras: list<array>, rejected: list<array>}
     */
    public function receive(DispatchManifest $container, array $scanned, ?string $remarks, ?int $actorId, int $receivingBranchId): array
    {
        $result = DB::transaction(function () use ($container, $scanned, $remarks, $actorId, $receivingBranchId) {
            $container = DispatchManifest::query()->lockForUpdate()->findOrFail($container->id);
            $this->assertReceivable($container, $receivingBranchId);
            $here = (int) $container->to_branch_id;

            $scan = $this->normalizeScanned($scanned);
            $items = $container->items()->with('shipment')->get();
            $label = $container->display_number;

            $received = [];
            $missing = [];
            $extras = [];
            $rejected = [];
            $matched = [];

            foreach ($items as $item) {
                if (! in_array($item->status, self::OPEN_ITEM_STATUSES, true)) {
                    continue;
                }
                $shipment = $item->shipment;
                if ($shipment && $this->alreadyHere($shipment, $here)) {
                    // Received earlier through the single-parcel path: close the line only.
                    $matched[(int) $shipment->id] = true;
                    $received[] = $this->closeAlreadyHere($container, $item, $shipment, $actorId);
                    continue;
                }
                $hit = $shipment && $this->scanMatches($scan, $shipment);
                if ($hit) {
                    $matched[(int) $shipment->id] = true;
                    $received[] = $this->receiveItem($container, $item, $shipment, $here, $actorId);
                    continue;
                }

                $item->forceFill([
                    'status' => 'missing',
                    'discrepancy' => 'missing',
                    'discrepancy_note' => 'Not in '.$label.' on arrival'.($remarks ? ': '.$remarks : ''),
                ])->save();
                if ($shipment) {
                    $this->trackInternal($shipment, "Not found in {$label} on arrival at ".$this->branchName($here).'. Flagged missing.', $actorId);
                }
                $missing[] = $this->itemRow($item, $shipment);
            }

            // Already-received parcels re-scanned: ignore quietly.
            foreach ($items as $item) {
                if ($item->shipment_id) {
                    $matched[(int) $item->shipment_id] = true;
                }
            }

            foreach ($scan['ids'] as $id) {
                if (isset($matched[$id])) {
                    continue;
                }
                $extra = $this->tryReceiveExtra($container, Shipment::query()->find($id), (string) $id, $here, $actorId);
                if ($extra['ok']) {
                    $extras[] = $extra['row'];
                    $matched[(int) $id] = true;
                } else {
                    $rejected[] = $extra['row'];
                }
            }
            foreach ($scan['codes'] as $code) {
                $shipment = Shipment::query()->where('tracking_number', $code)->first();
                if ($shipment && isset($matched[(int) $shipment->id])) {
                    continue;
                }
                $extra = $this->tryReceiveExtra($container, $shipment, $code, $here, $actorId);
                if ($extra['ok']) {
                    $extras[] = $extra['row'];
                    $matched[(int) $shipment->id] = true;
                } else {
                    $rejected[] = $extra['row'];
                }
            }

            $this->hops->refreshCounts((int) $container->id);
            $short = DispatchManifestItem::query()->where('dispatch_manifest_id', $container->id)->whereIn('status', ['missing', 'lost'])->exists();
            $container->forceFill([
                'status' => $short ? DispatchManifest::STATUS_PARTIALLY_RECEIVED : DispatchManifest::STATUS_RECEIVED,
                'received_by' => $actorId,
                'received_at' => $container->received_at ?? now(),
                'notes' => $remarks ? trim(($container->notes ? $container->notes."\n" : '').'Receive: '.$remarks) : $container->notes,
            ])->save();

            return [
                'container' => $container,
                'received' => $received,
                'missing' => $missing,
                'extras' => $extras,
                'rejected' => $rejected,
            ];
        });

        $result['container'] = $result['container']->fresh(['items.shipment', 'fromBranch', 'toBranch', 'rider']);
        if ($result['missing'] !== []) {
            $c = $result['container'];
            $n = count($result['missing']);
            $this->notify(
                (int) $c->from_branch_id,
                null,
                "{$c->display_number}: {$n} parcel".($n === 1 ? '' : 's').' missing on arrival',
                sprintf('%s received %s but %s not found: %s.', $this->branchName((int) $c->to_branch_id), $c->display_number,
                    $n === 1 ? 'this parcel was' : 'these parcels were',
                    implode(', ', array_filter(array_column($result['missing'], 'tracking_number')))),
            );
        }

        return $result;
    }

    /**
     * Resolve a missing parcel: "found" receives it now (and sorts it), "lost"
     * closes the line as lost and notifies the dispatching branch.
     */
    public function resolveItem(DispatchManifest $container, DispatchManifestItem $item, string $action, ?string $note, ?int $actorId, int $receivingBranchId): array
    {
        if ((int) $item->dispatch_manifest_id !== (int) $container->id) {
            abort(404);
        }

        $out = DB::transaction(function () use ($container, $item, $action, $note, $actorId, $receivingBranchId) {
            $container = DispatchManifest::query()->lockForUpdate()->findOrFail($container->id);
            $item = DispatchManifestItem::query()->lockForUpdate()->findOrFail($item->id);
            $this->assertBranchMayReceive($container, $receivingBranchId);
            $here = (int) $container->to_branch_id;

            if ($item->status !== 'missing') {
                throw ValidationException::withMessages(['item' => ['Only a missing parcel can be resolved.']]);
            }

            $shipment = Shipment::query()->find($item->shipment_id);
            $row = null;

            if ($action === 'found') {
                if (! $shipment || ! in_array($shipment->status, self::IN_TRANSIT_SHIPMENT_STATUSES, true)) {
                    throw ValidationException::withMessages(['item' => [
                        'This parcel is no longer in transit (it was received elsewhere or changed). Check its timeline.',
                    ]]);
                }
                $row = $this->receiveItem($container, $item, $shipment, $here, $actorId);
                $item->forceFill(['discrepancy_note' => trim('Found after receive. '.($note ?? ''))])->save();
            } else {
                $item->forceFill([
                    'status' => 'lost',
                    'discrepancy' => 'missing',
                    'discrepancy_note' => trim('Declared lost. '.($note ?? '')),
                ])->save();
                if ($shipment) {
                    $this->hops->closeHopForManifest((int) $shipment->id, (int) $container->id);
                    $this->trackInternal($shipment, "Declared lost on {$container->display_number}".($note ? ": {$note}" : '.'), $actorId);
                }
                $this->notify(
                    (int) $container->from_branch_id,
                    $shipment?->id,
                    "Parcel lost on {$container->display_number}",
                    sprintf('%s was not received at %s and is declared lost.', $shipment?->tracking_number ?? '#'.$item->shipment_id, $this->branchName($here)),
                );
                $row = $this->itemRow($item->fresh(), $shipment);
            }

            $this->hops->refreshCounts((int) $container->id);
            $short = DispatchManifestItem::query()->where('dispatch_manifest_id', $container->id)->whereIn('status', ['missing', 'lost'])->exists();
            $container->forceFill(['status' => $short ? DispatchManifest::STATUS_PARTIALLY_RECEIVED : DispatchManifest::STATUS_RECEIVED])->save();

            return ['container' => $container, 'item' => $row, 'action' => $action];
        });

        $out['container'] = $out['container']->fresh(['items.shipment', 'fromBranch', 'toBranch', 'rider']);

        return $out;
    }

    /**
     * Receive one parcel at $branchId and sort it: final destination -> last
     * mile (sorted_for_delivery + pending delivery), otherwise received at
     * transit hub -> sorted_for_transfer with the next hop recalculated.
     * Shared by the TR receive and the per-parcel receive endpoints.
     *
     * @return array{mode:string, shipment:Shipment}
     */
    public function receiveAndSort(Shipment $shipment, int $branchId, ?int $actorId, ?string $label = null): array
    {
        $shipment = Shipment::query()->lockForUpdate()->findOrFail($shipment->id);

        if ($shipment->status === CourierStatus::DISPATCHED_TO_DESTINATION_BRANCH) {
            // Legacy in-transit alias: normalise so the destination receive accepts it.
            $shipment->forceFill(['status' => CourierStatus::IN_TRANSIT])->save();
        }

        if ($this->isFinalHere($shipment, $branchId)) {
            $sorted = app(TransferService::class)->receiveAtDestination($shipment, $actorId);

            return ['mode' => 'last_mile', 'shipment' => $sorted];
        }

        $shipment->update([
            'status' => CourierStatus::RECEIVED_AT_TRANSIT_HUB,
            'merchant_status' => CourierStatus::merchantStatus(CourierStatus::RECEIVED_AT_TRANSIT_HUB),
            'current_branch_id' => $branchId,
            'current_sub_branch_id' => null,
        ]);
        $this->hops->markReceived((int) $shipment->id, $branchId, $actorId);
        $this->progress->applyProgressToShipment($shipment->fresh(), $branchId);

        app(TrackingService::class)->record(
            $shipment->fresh(),
            CourierStatus::RECEIVED_AT_TRANSIT_HUB,
            'Received at transit hub '.$this->branchName($branchId).($label ? " from {$label}" : '').'.',
            $actorId
        );

        $sorted = app(ShipmentSortingService::class)->sort($shipment->fresh(), $actorId);
        if ($sorted->status === CourierStatus::SORTED_FOR_TRANSFER) {
            $sorted = $this->progress->applyProgressToShipment($sorted, $branchId);
        }

        return ['mode' => $sorted->status === CourierStatus::SORTED_FOR_DELIVERY ? 'last_mile' : 'onward', 'shipment' => $sorted];
    }

    public function isFinalHere(Shipment $shipment, int $branchId): bool
    {
        $dest = (int) ($shipment->destination_branch_id ?? 0);

        return $dest > 0 && ($dest === $branchId || $this->progress->sameOperationalLocation($dest, $branchId));
    }

    /* ------------------------------------------------------------------ */
    /* Presentation                                                        */
    /* ------------------------------------------------------------------ */

    /** Compact container summary for lists. */
    public function summarize(DispatchManifest $m, bool $withItems = false): array
    {
        $m->loadMissing(['fromBranch:id,name', 'toBranch:id,name', 'rider:id,name,phone']);
        $items = $m->relationLoaded('items') ? $m->items : $m->items()->with('shipment')->get();
        $active = $items->reject(fn ($i) => in_array($i->status, ['cancelled', 'moved'], true));

        $lastMile = 0;
        $onward = [];
        foreach ($active as $i) {
            $s = $i->shipment;
            if (! $s) {
                continue;
            }
            if ($this->isFinalHere($s, (int) $m->to_branch_id)) {
                $lastMile++;
            } else {
                $key = (int) $s->destination_branch_id;
                $onward[$key] = ($onward[$key] ?? 0) + 1;
            }
        }
        $onwardNames = Branch::query()->whereIn('id', array_keys($onward))->pluck('name', 'id');

        $row = [
            'id' => $m->id,
            'transfer_number' => $m->transfer_number ?? null,
            'manifest_number' => $m->manifest_number,
            'display_number' => $m->display_number,
            'status' => $m->status,
            'from_branch' => $m->fromBranch ? ['id' => $m->fromBranch->id, 'name' => $m->fromBranch->name] : null,
            'to_branch' => $m->toBranch ? ['id' => $m->toBranch->id, 'name' => $m->toBranch->name] : null,
            'vehicle_type' => $m->vehicle_type ?? null,
            'vehicle_number' => $m->vehicle_number,
            'rider' => $m->rider ? ['id' => $m->rider->id, 'name' => $m->rider->name, 'phone' => $m->rider->phone ?? null] : null,
            'driver_name' => $m->driver_name,
            'driver_phone' => $m->driver_phone ?? null,
            'seal_number' => $m->seal_number,
            'notes' => $m->notes ?? null,
            'transport_cost' => (float) ($m->transport_cost ?? 0),
            'transport_cost_split_mode' => $m->transport_cost_split_mode ?? 'equal',
            'expected_count' => (int) ($m->expected_count ?? $active->where('is_extra', false)->count()),
            'received_count' => (int) ($m->received_count ?? 0),
            'missing_count' => (int) ($m->missing_count ?? 0),
            'extra_count' => (int) ($m->extra_count ?? 0),
            'parcel_count' => $active->count(),
            'last_mile_count' => $lastMile,
            'onward_count' => array_sum($onward),
            'onward_breakdown' => collect($onward)->map(fn ($c, $id) => [
                'destination_branch_id' => (int) $id,
                'destination_name' => $onwardNames[$id] ?? "Branch #{$id}",
                'count' => $c,
            ])->values()->all(),
            'dispatched_at' => optional($m->dispatched_at)->toIso8601String(),
            'received_at' => optional($m->received_at)->toIso8601String(),
            'cancelled_at' => optional($m->cancelled_at)->toIso8601String(),
            'cancel_reason' => $m->cancel_reason ?? null,
            'created_at' => optional($m->created_at)->toIso8601String(),
        ];

        if ($withItems) {
            $row['items'] = $items->map(fn ($i) => $this->itemRow($i, $i->shipment, (int) $m->to_branch_id))->values()->all();
        }

        return $row;
    }

    public function itemRow(DispatchManifestItem $item, ?Shipment $shipment, ?int $hereBranchId = null): array
    {
        return [
            'item_id' => $item->id,
            'shipment_id' => $item->shipment_id,
            'tracking_number' => $shipment?->tracking_number,
            'receiver_name' => $shipment?->receiver_name,
            'receiver_phone' => $shipment?->receiver_phone,
            'destination_branch_id' => $shipment?->destination_branch_id,
            'destination_name' => $shipment?->destinationBranch?->name,
            'weight' => (float) ($shipment?->chargeable_weight ?: $shipment?->weight ?: 0),
            'payment_type' => $shipment?->payment_type,
            'shipment_status' => $shipment?->status,
            'is_final_here' => $shipment && $hereBranchId ? $this->isFinalHere($shipment, $hereBranchId) : null,
            'status' => $item->status,
            'is_extra' => (bool) ($item->is_extra ?? false),
            'discrepancy' => $item->discrepancy ?? null,
            'discrepancy_note' => $item->discrepancy_note ?? null,
            'transport_cost' => (float) ($item->transport_cost ?? 0),
            'scanned_at' => optional($item->scanned_at)->toIso8601String(),
            'received_at' => optional($item->received_at)->toIso8601String(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                           */
    /* ------------------------------------------------------------------ */

    private function receiveItem(DispatchManifest $container, DispatchManifestItem $item, Shipment $shipment, int $here, ?int $actorId): array
    {
        if (! in_array($shipment->status, self::IN_TRANSIT_SHIPMENT_STATUSES, true)) {
            throw ValidationException::withMessages(['scanned' => [
                sprintf('%s is %s, not in transit. Fix it from its timeline before receiving.', $shipment->tracking_number, str_replace('_', ' ', (string) $shipment->status)),
            ]]);
        }

        $item->forceFill([
            'status' => 'received',
            'received_at' => now(),
            'received_by' => $actorId,
            'scanned_at' => $item->scanned_at ?? now(),
        ])->save();
        $this->hops->closeHopForManifest((int) $shipment->id, (int) $container->id);

        $res = $this->receiveAndSort($shipment, $here, $actorId, $container->display_number);

        return $this->receivedRow($item->fresh(), $res);
    }

    /**
     * A parcel that arrived but is not on this TR. Accepted when it is in
     * transit and this branch is its next hop, final destination or a stop
     * on its remaining path. Its other open TR line is closed as "moved".
     *
     * @return array{ok:bool,row:array}
     */
    private function tryReceiveExtra(DispatchManifest $container, ?Shipment $shipment, string $code, int $here, ?int $actorId): array
    {
        if (! $shipment) {
            return ['ok' => false, 'row' => ['code' => $code, 'reason' => 'No shipment with this tracking number.']];
        }
        $base = ['code' => $code, 'shipment_id' => $shipment->id, 'tracking_number' => $shipment->tracking_number];

        if (! in_array($shipment->status, self::IN_TRANSIT_SHIPMENT_STATUSES, true)) {
            return ['ok' => false, 'row' => $base + ['reason' => 'Not in transit (status: '.str_replace('_', ' ', (string) $shipment->status).').']];
        }

        $headedHere = $this->isFinalHere($shipment, $here)
            || (int) ($shipment->next_hop_branch_id ?? 0) === $here
            || in_array($here, array_map('intval', $this->progress->resolveForShipment($shipment, (int) ($shipment->origin_branch_id ?? 0) ?: null)['path_branch_ids'] ?? []), true);
        if (! $headedHere) {
            return ['ok' => false, 'row' => $base + ['reason' => 'Not headed to '.$this->branchName($here).'. Send it back or re-route it from its timeline.']];
        }

        // Close the TR line it was booked on (it physically came on this TR).
        $other = DispatchManifestItem::query()
            ->where('shipment_id', $shipment->id)
            ->where('dispatch_manifest_id', '!=', $container->id)
            ->whereIn('status', array_merge(self::OPEN_ITEM_STATUSES, ['missing']))
            ->latest('id')
            ->first();
        $otherLabel = null;
        if ($other) {
            $otherManifest = DispatchManifest::query()->find($other->dispatch_manifest_id);
            $otherLabel = $otherManifest?->display_number;
            $other->forceFill([
                'status' => 'moved',
                'discrepancy' => 'missing',
                'discrepancy_note' => 'Arrived at '.$this->branchName($here).' on '.$container->display_number,
            ])->save();
            $this->hops->closeHopForManifest((int) $shipment->id, (int) $other->dispatch_manifest_id);
            $this->hops->closeManifestIfDone((int) $other->dispatch_manifest_id, $actorId);
        }

        $item = DispatchManifestItem::query()->updateOrCreate(
            ['dispatch_manifest_id' => $container->id, 'shipment_id' => $shipment->id],
            [
                'status' => 'received',
                'is_extra' => true,
                'discrepancy' => 'extra',
                'discrepancy_note' => $otherLabel ? "Booked on {$otherLabel}" : 'Not on any open TR',
                'transport_cost' => 0,
                'received_at' => now(),
                'received_by' => $actorId,
                'scanned_at' => now(),
            ],
        );

        $res = $this->receiveAndSort($shipment, $here, $actorId, $container->display_number);

        return ['ok' => true, 'row' => $this->receivedRow($item->fresh(), $res) + ['booked_on' => $otherLabel]];
    }

    /** Parcel already checked in at this branch (e.g. via the single-parcel receive). */
    private function alreadyHere(Shipment $shipment, int $here): bool
    {
        return ! in_array($shipment->status, self::IN_TRANSIT_SHIPMENT_STATUSES, true)
            && ((int) ($shipment->current_branch_id ?? 0) === $here
                || $this->progress->sameOperationalLocation((int) ($shipment->current_branch_id ?? 0), $here));
    }

    private function closeAlreadyHere(DispatchManifest $container, DispatchManifestItem $item, Shipment $shipment, ?int $actorId): array
    {
        $item->forceFill([
            'status' => 'received',
            'received_at' => $item->received_at ?? now(),
            'received_by' => $item->received_by ?? $actorId,
        ])->save();
        $this->hops->closeHopForManifest((int) $shipment->id, (int) $container->id);

        return $this->receivedRow($item->fresh(), [
            'mode' => in_array($shipment->status, [CourierStatus::SORTED_FOR_TRANSFER, CourierStatus::RECEIVED_AT_TRANSIT_HUB], true) ? 'onward' : 'last_mile',
            'shipment' => $shipment,
        ]) + ['already_received' => true];
    }

    private function receivedRow(DispatchManifestItem $item, array $res): array
    {
        /** @var Shipment $s */
        $s = $res['shipment'];
        $next = (int) ($s->next_hop_branch_id ?? 0);

        return [
            'item_id' => $item->id,
            'shipment_id' => $s->id,
            'tracking_number' => $s->tracking_number,
            'result' => $res['mode'],
            'status' => $s->status,
            'next_hop_branch_id' => $next ?: null,
            'next_hop_name' => $next ? $this->branchName($next) : null,
            'destination_name' => $this->branchName((int) $s->destination_branch_id),
            'is_extra' => (bool) ($item->is_extra ?? false),
        ];
    }

    private function sendContainer(DispatchManifest $container, ?int $actorId, array $rows): void
    {
        $columns = Schema::getColumnListing('shipments');
        $tracking = app(TrackingService::class);
        $hopName = $this->branchName((int) $container->to_branch_id);

        foreach ($rows as $row) {
            /** @var Shipment $shipment */
            $shipment = $row['shipment'];
            $p = $row['progress'];

            DispatchManifestItem::query()
                ->where('dispatch_manifest_id', $container->id)
                ->where('shipment_id', $shipment->id)
                ->update(['status' => 'sent', 'updated_at' => now()]);

            $updates = [
                'status' => CourierStatus::IN_TRANSIT,
                'merchant_status' => CourierStatus::merchantStatus(CourierStatus::IN_TRANSIT),
                'current_branch_id' => null,
                'current_sub_branch_id' => null,
            ];
            $optional = [
                'transfer_status' => CourierStatus::IN_TRANSIT,
                'dispatched_at' => now(),
                'transfer_route_id' => $p['transfer_route_id'] ?? $shipment->transfer_route_id,
                'next_hop_branch_id' => (int) $container->to_branch_id,
                'transfer_leg_index' => $p['transfer_leg_index'] ?? null,
                'path_text' => $p['path_text'] ?? null,
            ];
            foreach ($optional as $col => $val) {
                if (in_array($col, $columns, true) && ($val !== null || $col === 'transfer_leg_index')) {
                    $updates[$col] = $val;
                }
            }
            $shipment->update($updates);

            $tracking->record(
                $shipment->fresh(),
                CourierStatus::IN_TRANSIT,
                "Dispatched on {$container->display_number} toward {$hopName}"
                    .(! empty($p['route_code']) ? " (Route: {$p['route_code']})" : ''),
                $actorId
            );
        }

        $container->forceFill([
            'status' => DispatchManifest::STATUS_DISPATCHED,
            'dispatched_at' => now(),
        ])->save();

        $this->hops->recordDispatch($container->fresh('items'));
        $this->hops->refreshCounts((int) $container->id);
    }

    /**
     * Rows for parcels already loaded (status "added") on an open TR, minus
     * $skipIds. Parcels that changed since loading are dropped from the TR
     * (line cancelled with the reason) instead of failing the whole trip.
     *
     * @return list<array{shipment: Shipment, progress: array}>
     */
    private function heldRows(DispatchManifest $container, array $skipIds): array
    {
        $rows = [];
        $from = (int) $container->from_branch_id;
        $items = $container->items()->where('status', 'added')->whereNotIn('shipment_id', $skipIds ?: [0])->with('shipment')->get();
        foreach ($items as $item) {
            $shipment = $item->shipment;
            try {
                if (! $shipment || ! $this->isDispatchableStatus($shipment)) {
                    throw ValidationException::withMessages(['shipment_ids' => ['Parcel is no longer ready for transfer.']]);
                }
                $this->progress->assertNextHopMatches($shipment, (int) $container->to_branch_id, $from);
            } catch (ValidationException $e) {
                $item->forceFill(['status' => 'cancelled', 'discrepancy_note' => collect($e->errors())->flatten()->first()])->save();
                continue;
            }
            $rows[] = ['shipment' => $shipment, 'progress' => $this->progress->resolveForShipment($shipment, $from)];
        }

        return $rows;
    }

    private function createContainer(int $from, int $hop, ?int $actorId): DispatchManifest
    {
        $attempts = 0;
        while (true) {
            $attempts++;
            try {
                return DB::transaction(function () use ($from, $hop, $actorId) {
                    $number = $this->nextTransferNumber();
                    $payload = [
                        'manifest_number' => $number,
                        'transfer_number' => $number,
                        'from_branch_id' => $from,
                        'to_branch_id' => $hop,
                        'status' => DispatchManifest::STATUS_OPEN,
                        'created_by' => $actorId,
                    ];
                    $schema = Schema::getColumnListing('dispatch_manifests');
                    foreach (['is_multi_hop' => true, 'route_code' => null, 'transit_branch_ids' => []] as $col => $val) {
                        if (in_array($col, $schema, true)) {
                            $payload[$col] = $val;
                        }
                    }

                    return DispatchManifest::create($payload);
                });
            } catch (QueryException $e) {
                // Unique collision from a concurrent dispatch: take the next number.
                if ($attempts >= 5 || ! str_contains(strtolower($e->getMessage()), 'duplicate')) {
                    throw $e;
                }
            }
        }
    }

    private function openContainerFor(int $from, int $hop, bool $lock = false): ?DispatchManifest
    {
        $q = DispatchManifest::query()
            ->where('from_branch_id', $from)
            ->where('to_branch_id', $hop)
            ->where('status', DispatchManifest::STATUS_OPEN)
            ->latest('id');
        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->first();
    }

    private function applyMeta(DispatchManifest $container, array $meta): void
    {
        $fill = [];
        foreach (['vehicle_type', 'vehicle_number', 'rider_user_id', 'driver_name', 'driver_phone', 'seal_number', 'notes'] as $key) {
            if (array_key_exists($key, $meta) && $meta[$key] !== null && $meta[$key] !== '') {
                $fill[$key] = $meta[$key];
            }
        }

        // Staff rider picked: copy name / phone so the TR prints without joins.
        if (! empty($fill['rider_user_id'])) {
            $rider = DB::table('users')->where('id', $fill['rider_user_id'])->first(['name', 'phone']);
            if ($rider) {
                $fill['driver_name'] = $fill['driver_name'] ?? $rider->name;
                if (! empty($rider->phone)) {
                    $fill['driver_phone'] = $fill['driver_phone'] ?? $rider->phone;
                }
            }
        }

        $schema = Schema::getColumnListing('dispatch_manifests');
        $fill = array_intersect_key($fill, array_flip($schema));
        if ($fill !== []) {
            $container->forceFill($fill)->save();
        }
    }

    private function validateMeta(array $meta): void
    {
        if (! empty($meta['vehicle_type']) && ! in_array($meta['vehicle_type'], DispatchManifest::VEHICLE_TYPES, true)) {
            throw ValidationException::withMessages(['vehicle_type' => ['Vehicle type must be one of: '.implode(', ', DispatchManifest::VEHICLE_TYPES).'.']]);
        }
    }

    private function isDispatchableStatus(Shipment $shipment): bool
    {
        return in_array($shipment->status, [
            CourierStatus::SORTED_FOR_TRANSFER,
            CourierStatus::RECEIVED_AT_ORIGIN_BRANCH,
            CourierStatus::RECEIVED_AT_TRANSIT_HUB,
            CourierStatus::PICKED_UP,
            CourierStatus::SORTED_FOR_DELIVERY,
        ], true);
    }

    private function assertReceivable(DispatchManifest $container, int $receivingBranchId): void
    {
        if (! in_array($container->status, self::RECEIVABLE_STATUSES, true)) {
            $msg = match ($container->status) {
                DispatchManifest::STATUS_OPEN => 'This TR has not been dispatched yet.',
                DispatchManifest::STATUS_RECEIVED => 'This TR is already received.',
                DispatchManifest::STATUS_CANCELLED => 'This TR was cancelled.',
                default => 'This TR cannot be received in status '.$container->status.'.',
            };
            throw ValidationException::withMessages(['status' => [$msg]]);
        }
        if ($container->status === DispatchManifest::STATUS_PARTIALLY_RECEIVED) {
            throw ValidationException::withMessages(['status' => [
                'This TR is already checked in. Resolve its missing parcels (found / lost) instead.',
            ]]);
        }
        $this->assertBranchMayReceive($container, $receivingBranchId);
    }

    private function assertBranchMayReceive(DispatchManifest $container, int $receivingBranchId): void
    {
        if ($receivingBranchId !== 0
            && (int) $container->to_branch_id !== $receivingBranchId
            && ! $this->progress->sameOperationalLocation((int) $container->to_branch_id, $receivingBranchId)) {
            throw ValidationException::withMessages(['branch_id' => [
                'This TR is headed to '.$this->branchName((int) $container->to_branch_id).', not your branch.',
            ]]);
        }
    }

    /** @return array{ids: list<int>, codes: list<string>} */
    private function normalizeScanned(array $scanned): array
    {
        $ids = [];
        $codes = [];
        foreach ($scanned as $value) {
            if (is_int($value) || (is_string($value) && ctype_digit(trim($value)))) {
                $ids[] = (int) $value;
            } elseif (is_string($value) && trim($value) !== '') {
                $codes[] = strtoupper(trim($value));
            }
        }

        return ['ids' => array_values(array_unique($ids)), 'codes' => array_values(array_unique($codes))];
    }

    private function scanMatches(array $scan, Shipment $shipment): bool
    {
        return in_array((int) $shipment->id, $scan['ids'], true)
            || in_array(strtoupper((string) $shipment->tracking_number), $scan['codes'], true);
    }

    private function trackInternal(Shipment $shipment, string $description, ?int $actorId): void
    {
        try {
            app(TrackingService::class)->record($shipment, (string) $shipment->status, $description, $actorId, 'internal');
        } catch (\Throwable $e) {
            Log::warning('transfer_container.track_failed', ['shipment_id' => $shipment->id, 'error' => $e->getMessage()]);
        }
    }

    private function notifyNextBranch(DispatchManifest $m): void
    {
        $count = (int) ($m->expected_count ?: $m->items()->where('status', 'sent')->count());
        $how = trim(implode(' ', array_filter([
            $m->vehicle_type ? ucfirst(str_replace('_', ' ', (string) $m->vehicle_type)) : null,
            $m->vehicle_number,
            $m->driver_name ? 'driver '.$m->driver_name : null,
        ])));
        $this->notify(
            (int) $m->to_branch_id,
            null,
            "Incoming {$m->display_number}: {$count} parcel".($count === 1 ? '' : 's'),
            sprintf('%s dispatched %s with %d parcel%s to your branch%s. Receive it from Transfers > In Transit.',
                $this->branchName((int) $m->from_branch_id), $m->display_number, $count, $count === 1 ? '' : 's', $how !== '' ? " ({$how})" : ''),
        );
    }

    private function notify(int $branchId, ?int $shipmentId, string $title, string $message): void
    {
        try {
            if (! Schema::hasTable('staff_notifications')) {
                return;
            }
            $cols = Schema::getColumnListing('staff_notifications');
            $row = array_intersect_key([
                'title' => $title,
                'message' => $message,
                'branch_id' => $branchId ?: null,
                'shipment_id' => $shipmentId,
                'type' => 'transfer',
                'is_read' => false,
                'data_json' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ], array_flip($cols));
            DB::table('staff_notifications')->insert($row);
        } catch (\Throwable $e) {
            Log::info('transfer_container.notify_skipped', ['branch_id' => $branchId, 'error' => $e->getMessage()]);
        }
    }

    private function branchName(?int $branchId): string
    {
        if (! $branchId) {
            return 'branch';
        }
        static $cache = [];

        return $cache[$branchId] ??= (string) (Branch::query()->whereKey($branchId)->value('name') ?? "Branch #{$branchId}");
    }
}
