<?php

declare(strict_types=1);

namespace Modules\Delivery\Services;

use App\Models\User;
use App\Support\CourierStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Branch\Models\Branch;
use Modules\Delivery\Models\DeliveryAssignment;
use Modules\Delivery\Models\DeliveryRun;
use Modules\Delivery\Models\DeliveryRunItem;
use Modules\Dispatch\Services\TransferContainerService;
use Modules\POD\Models\PodDeposit;
use Modules\POD\Models\PodRecord;
use Modules\Settlement\Services\SettlementWorkflowService;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Services\ShipmentCallbackService;
use Modules\Tracking\Services\TrackingService;

/**
 * DR delivery runs: the last-mile container, like PR for pickup and TR for
 * transfers.
 *
 *   create()        parcels sorted for delivery at a branch -> DR-KATHM-000001 (open)
 *   addItems() / removeItems()   change the list until the run is dispatched
 *   assignRider()   rider of that branch (open -> assigned)
 *   dispatch()      hand-over: each parcel's delivery is assigned to the rider and
 *                   marked out for delivery through DeliveryWorkflowService
 *   syncFromDelivery()  the rider's normal per-delivery actions (arrived, POD,
 *                   delivered, failed) roll up into the run; all done -> completed
 *   close()         back at branch: undelivered parcels are failed, then queued
 *                   for re-delivery or returned; rider cash is checked against
 *                   COD collected and deposited into the branch POD pool
 *   cancel()        only before dispatch
 *
 * A parcel can be on one active run only. Deliveries made without a run keep
 * working exactly as before.
 */
final class DeliveryRunService
{
    /** Shipment statuses that can be put on a run. */
    public const READY_STATUSES = [
        CourierStatus::SORTED_FOR_DELIVERY,
        CourierStatus::DELIVERY_FAILED, // earlier failed attempt: re-delivery
    ];

    /** Roles that may ride a DR (same pool as the deliveries rider picker). */
    /** Runs that have not left the branch yet (parcels can still be added). */
    public const UNSTARTED_STATUSES = [DeliveryRun::STATUS_OPEN, DeliveryRun::STATUS_ASSIGNED];

    /** Stops within this distance (km) of a run stop count as on its route. */
    public const ROUTE_RADIUS_KM = 2.0;

    public const RIDER_ROLES = ['rider', 'delivery_staff', 'pickup_rider', 'staff', 'sub_branch_manager', 'branch_manager'];

    public function __construct(
        private readonly DeliveryWorkflowService $workflow,
        private readonly TrackingService $tracking,
    ) {
    }

    /* ------------------------------------------------------------------ */
    /* Numbering                                                           */
    /* ------------------------------------------------------------------ */

    /** DR-RUN-{seq}, format DR-RUN-001, DR-RUN-002... */
    public function nextRunNumber(int $branchId): string
    {
        $last = DB::table('delivery_runs')
            ->where('run_number', 'like', 'DR-RUN-%')
            ->orderByRaw('LENGTH(run_number) DESC')
            ->orderByDesc('run_number')
            ->lockForUpdate()
            ->value('run_number');
        $number = ((is_string($last) && preg_match('/(\d+)$/', $last, $m)) ? (int) $m[1] : 0) + 1;

        do {
            $candidate = sprintf('DR-RUN-%03d', $number);
            $exists = DB::table('delivery_runs')->where('run_number', $candidate)->exists();
            $number++;
        } while ($exists);

        return $candidate;
    }

    /* ------------------------------------------------------------------ */
    /* Ready parcels                                                       */
    /* ------------------------------------------------------------------ */

    /** Parcels at the branch that can go on a new run (0 = every branch). */
    public function readyQuery(int $branchId): Builder
    {
        return Shipment::query()
            ->whereIn('status', self::READY_STATUSES)
            ->when($branchId > 0, fn (Builder $q) => $this->whereAtBranch($q, $branchId))
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('delivery_run_items as ri')
                    ->join('delivery_runs as r', 'r.id', '=', 'ri.delivery_run_id')
                    ->whereColumn('ri.shipment_id', 'shipments.id')
                    ->where('ri.status', '!=', DeliveryRunItem::STATUS_REMOVED)
                    ->whereIn('r.status', DeliveryRun::ACTIVE_STATUSES);
            })
            // Already handed to a rider through the old per-delivery assign.
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('delivery_assignments as da')
                    ->whereColumn('da.shipment_id', 'shipments.id')
                    ->whereIn('da.status', ['assigned', 'accepted', 'out_for_delivery']);
            });
    }

    /** @return list<array<string,mixed>> */
    public function readyList(int $branchId, ?string $search = null, int $limit = 500): array
    {
        $q = $this->readyQuery($branchId)->with('merchant:id,name');
        if ($search !== null && trim($search) !== '') {
            $term = trim($search);
            $q->where(function ($w) use ($term) {
                $w->where('tracking_number', 'like', "%{$term}%")
                    ->orWhere('receiver_name', 'like', "%{$term}%")
                    ->orWhere('receiver_phone', 'like', "%{$term}%")
                    ->orWhere('receiver_area', 'like', "%{$term}%")
                    ->orWhere('receiver_city', 'like', "%{$term}%")
                    ->orWhere('delivery_address', 'like', "%{$term}%");
            });
        }

        $rows = $q->orderBy('receiver_area')->orderBy('receiver_city')->orderBy('id')->limit($limit)->get();
        $deliveries = DeliveryAssignment::query()
            ->whereIn('shipment_id', $rows->pluck('id'))
            ->orderByDesc('id')
            ->get()
            ->groupBy('shipment_id');

        return $rows->map(function (Shipment $s) use ($deliveries) {
            $history = $deliveries->get($s->id, collect());
            $open = $history->first(fn ($d) => ! in_array($d->status, ['failed', 'cancelled', 'delivered'], true));

            return array_merge($this->shipmentRow($s), [
                'delivery_id' => $open?->id,
                'delivery_status' => $open?->status,
                'attempts' => $history->count(),
                'reattempt' => $s->status === CourierStatus::DELIVERY_FAILED,
                'last_failure_reason' => $history->firstWhere('status', 'failed')?->failure_reason,
            ]);
        })->values()->all();
    }

    /* ------------------------------------------------------------------ */
    /* Create / edit                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * @param  list<int>  $shipmentIds
     * @param  array{rider_user_id?:?int,vehicle_type?:?string,vehicle_number?:?string,notes?:?string,area_label?:?string,auto_sequence?:bool}  $meta
     * @return array{run: DeliveryRun, skipped: array<int,string>}
     */
    public function create(array $shipmentIds, int $scopeBranchId, array $meta, User $actor): array
    {
        $shipmentIds = $this->ids($shipmentIds);
        if ($shipmentIds === []) {
            throw ValidationException::withMessages(['shipment_ids' => ['Select at least one parcel.']]);
        }
        $this->validateVehicle($meta['vehicle_type'] ?? null);

        // One un-started run per rider and branch: new parcels for a rider who is
        // still collecting go onto that run unless a second run is asked for.
        $mode = (string) ($meta['run_mode'] ?? 'auto');
        if (! empty($meta['rider_user_id']) && $mode !== 'new') {
            $lookupBranch = $scopeBranchId > 0 ? $scopeBranchId : $this->branchOf(Shipment::query()->find($shipmentIds[0]));
            $existing = $this->unstartedRunFor((int) $meta['rider_user_id'], $lookupBranch);
            if ($existing) {
                $out = $this->addItems($existing, $shipmentIds, $actor);

                return ['run' => $out['run'], 'skipped' => $out['skipped'], 'added' => $out['added'], 'off_route' => $out['off_route'], 'merged' => true];
            }
        }

        $attempts = 0;
        while (true) {
            $attempts++;
            try {
                return DB::transaction(function () use ($shipmentIds, $scopeBranchId, $meta, $actor) {
                    $shipments = Shipment::query()->whereIn('id', $shipmentIds)->lockForUpdate()->get()->keyBy('id');

                    $branchId = $scopeBranchId > 0 ? $scopeBranchId : $this->branchOf($shipments->first());
                    if ($branchId <= 0) {
                        throw ValidationException::withMessages(['branch_id' => ['Choose the branch the run leaves from.']]);
                    }

                    [$ok, $skipped] = $this->partition($shipmentIds, $shipments, $branchId, null);
                    if ($ok === []) {
                        throw ValidationException::withMessages(['shipment_ids' => [
                            'None of the selected parcels can go on a delivery run: '.$this->reasonText($skipped),
                        ]]);
                    }

                    $rider = null;
                    if (! empty($meta['rider_user_id'])) {
                        $rider = $this->validateRider((int) $meta['rider_user_id'], $branchId);
                    }

                    $run = DeliveryRun::create(array_filter([
                        'run_number' => $this->nextRunNumber($branchId),
                        'branch_id' => $branchId,
                        'status' => $rider ? DeliveryRun::STATUS_ASSIGNED : DeliveryRun::STATUS_OPEN,
                        'rider_user_id' => $rider?->id,
                        'assigned_by' => $rider ? $actor->id : null,
                        'assigned_at' => $rider ? now() : null,
                        'vehicle_type' => $meta['vehicle_type'] ?? null,
                        'vehicle_number' => $meta['vehicle_number'] ?? null,
                        'area_label' => $meta['area_label'] ?? null,
                        'notes' => $meta['notes'] ?? null,
                        'created_by' => $actor->id,
                    ], fn ($v) => $v !== null));

                    $this->attach($run, $ok, $actor);
                    if (($meta['auto_sequence'] ?? true) !== false) {
                        $this->autoSequence($run);
                    }
                    $this->recount($run);

                    if ($rider) {
                        $this->notifyRider($run->fresh(), 'assigned');
                    }

                    return ['run' => $run->fresh(), 'skipped' => $skipped];
                });
            } catch (QueryException $e) {
                if ($attempts >= 5 || ! str_contains(strtolower($e->getMessage()), 'duplicate')) {
                    throw $e;
                }
            }
        }
    }

    /** @return array{run: DeliveryRun, added: list<int>, skipped: array<int,string>, off_route: list<string>} */
    public function addItems(DeliveryRun $run, array $shipmentIds, User $actor): array
    {
        $shipmentIds = $this->ids($shipmentIds);
        if ($shipmentIds === []) {
            throw ValidationException::withMessages(['shipment_ids' => ['Select at least one parcel to add.']]);
        }

        $result = DB::transaction(function () use ($run, $shipmentIds, $actor) {
            $run = $this->lockRun($run);
            $this->assertEditable($run, $run->status === DeliveryRun::STATUS_OUT
                ? "{$run->run_number} is already out for delivery; parcels cannot be added. Create a new run for the rider's next trip."
                : null);

            $shipments = Shipment::query()->whereIn('id', $shipmentIds)->lockForUpdate()->get()->keyBy('id');
            [$ok, $skipped] = $this->partition($shipmentIds, $shipments, (int) $run->branch_id, $run);
            $offRoute = [];
            if ($ok !== []) {
                $offRoute = $this->offRoute($run, $ok);
                $this->attach($run, $ok, $actor);
                $addedIds = array_map(fn (Shipment $s) => (int) $s->id, $ok);
                if ($run->status === DeliveryRun::STATUS_ASSIGNED && $this->hasCol('delivery_run_items', 'late_added_at')) {
                    // Added while the rider is collecting: highlighted as "New" until bagged.
                    DeliveryRunItem::query()->where('delivery_run_id', $run->id)
                        ->whereIn('shipment_id', $addedIds)
                        ->where('status', DeliveryRunItem::STATUS_QUEUED)
                        ->update(['late_added_at' => now()]);
                }
                $this->autoSequence($run);
                $this->recount($run);
            }

            return ['run' => $run->fresh(), 'added' => array_map(fn (Shipment $s) => (int) $s->id, $ok), 'skipped' => $skipped, 'off_route' => $offRoute];
        });

        if ($result['added'] !== [] && $result['run']->status === DeliveryRun::STATUS_ASSIGNED) {
            $this->notifyRider($result['run'], 'added', ['count' => count($result['added'])]);
        }

        return $result;
    }

    /** Rider's run at this branch that has not started yet (open/assigned). */
    public function unstartedRunFor(int $riderId, int $branchId, ?int $exceptRunId = null): ?DeliveryRun
    {
        if ($riderId <= 0) {
            return null;
        }

        return DeliveryRun::query()->where('rider_user_id', $riderId)
            ->whereIn('status', self::UNSTARTED_STATUSES)
            ->when($branchId > 0, fn ($q) => $q->whereIn('branch_id', $this->branchFamily($branchId)))
            ->when($exceptRunId, fn ($q) => $q->where('id', '!=', $exceptRunId))
            ->latest('id')->first();
    }

    /** Rider's run that is out for delivery now, if any. */
    public function onRoadRunFor(int $riderId, int $branchId): ?DeliveryRun
    {
        return DeliveryRun::query()->where('rider_user_id', $riderId)
            ->where('status', DeliveryRun::STATUS_OUT)
            ->when($branchId > 0, fn ($q) => $q->whereIn('branch_id', $this->branchFamily($branchId)))
            ->latest('id')->first();
    }

    /**
     * Un-started runs the selected parcels could join, best route match first.
     * A parcel fits a run when it shares an area with a stop or lies within
     * ROUTE_RADIUS_KM of one.
     *
     * @param  list<int>  $shipmentIds
     */
    public function suggestRuns(array $shipmentIds, int $scopeBranchId): array
    {
        $shipmentIds = array_slice($this->ids($shipmentIds), 0, 300);
        $selected = $shipmentIds ? Shipment::query()->whereIn('id', $shipmentIds)->get() : collect();
        $branchId = $scopeBranchId > 0 ? $scopeBranchId : $this->branchOf($selected->first());
        $family = $branchId > 0 ? $this->branchFamily($branchId) : [];

        $runs = DeliveryRun::query()->whereIn('status', self::UNSTARTED_STATUSES)
            ->when($family, fn ($q) => $q->whereIn('branch_id', $family))
            ->with('rider:id,name,phone')
            ->latest('id')->limit(60)->get();

        $rows = [];
        foreach ($runs as $run) {
            $stops = $this->routeStops($run);
            $match = $this->matchStops($selected->all(), $stops);
            $areas = array_count_values(array_filter(array_map(fn ($st) => $st['area'], $stops)));
            arsort($areas);
            $mainArea = $areas ? (string) array_key_first($areas) : ($run->area_label ?: null);
            $mainLabel = $mainArea ? Str::title($mainArea) : null;
            $summary = $this->summarize($run);
            $rows[] = [
                'id' => $run->id,
                'run_number' => $run->run_number,
                'status' => $run->status,
                'rider' => $run->rider ? ['id' => $run->rider->id, 'name' => $run->rider->name] : null,
                'stops' => count($stops),
                'bagged_count' => $summary['bagged_count'],
                'to_bag_count' => $summary['to_bag_count'],
                'cod_expected' => $this->liveCodExpected($run),
                'main_area' => $mainLabel,
                'areas' => array_map(fn ($a) => Str::title((string) $a), array_slice(array_keys($areas), 0, 6)),
                'fits' => $match['fit_count'] > 0,
                'fit_count' => $match['fit_count'],
                'selected_count' => $selected->count(),
                'shared_areas' => $match['shared_areas'],
                'nearest_km' => $match['nearest_km'],
                'off_route' => $match['off_route'],
                'label' => 'Fits '.$run->run_number.' ('.($run->rider?->name ?? 'no rider').($mainLabel ? ', '.$mainLabel.' route' : '').')',
            ];
        }
        usort($rows, fn ($a, $b) => [$b['fit_count'], $a['nearest_km'] ?? 999] <=> [$a['fit_count'], $b['nearest_km'] ?? 999]);

        $onRoad = DeliveryRun::query()->where('status', DeliveryRun::STATUS_OUT)
            ->when($family, fn ($q) => $q->whereIn('branch_id', $family))
            ->whereNotNull('rider_user_id')->with('rider:id,name')->get()
            ->map(fn (DeliveryRun $r) => [
                'id' => $r->id,
                'run_number' => $r->run_number,
                'rider' => $r->rider ? ['id' => $r->rider->id, 'name' => $r->rider->name] : null,
                'pending_count' => max(0, (int) $r->expected_count - (int) $r->delivered_count - (int) $r->failed_count),
            ])->values()->all();

        return ['branch_id' => $branchId, 'runs' => $rows, 'on_road' => $onRoad];
    }

    /** @return list<array{area:?string,lat:?float,lng:?float,coverage:?int}> */
    private function routeStops(DeliveryRun $run): array
    {
        $items = DeliveryRunItem::query()->where('delivery_run_id', $run->id)
            ->whereIn('status', [DeliveryRunItem::STATUS_QUEUED, DeliveryRunItem::STATUS_BAGGED])
            ->with('shipment')->get();

        return $items->map(fn (DeliveryRunItem $i) => $this->stopPoint($i->shipment))->filter()->values()->all();
    }

    private function stopPoint(?Shipment $s): ?array
    {
        if (! $s) {
            return null;
        }
        $lat = is_numeric($s->delivery_lat) ? (float) $s->delivery_lat : null;
        $lng = is_numeric($s->delivery_lng) ? (float) $s->delivery_lng : null;
        if ($lat !== null && $lng !== null && abs($lat) < 0.001 && abs($lng) < 0.001) {
            $lat = $lng = null;
        }
        $area = $this->displayArea($s);
        $coverage = null;
        foreach (['coverage_location_id', 'delivery_coverage_location_id'] as $col) {
            if (isset($s->{$col}) && (int) $s->{$col} > 0) {
                $coverage = (int) $s->{$col};
                break;
            }
        }

        return [
            'tracking' => (string) $s->tracking_number,
            'area' => $area ? Str::lower(trim($area)) : null,
            'lat' => $lat,
            'lng' => $lng,
            'coverage' => $coverage,
        ];
    }

    /**
     * @param  list<Shipment>  $selected
     * @param  list<array>  $stops
     */
    private function matchStops(array $selected, array $stops): array
    {
        $fit = 0;
        $shared = [];
        $nearest = null;
        $off = [];
        if ($stops === []) {
            return ['fit_count' => 0, 'shared_areas' => [], 'nearest_km' => null, 'off_route' => []];
        }
        $areas = array_filter(array_map(fn ($st) => $st['area'], $stops));
        $coverages = array_filter(array_map(fn ($st) => $st['coverage'], $stops));
        foreach ($selected as $s) {
            $p = $this->stopPoint($s);
            if (! $p) {
                continue;
            }
            $ok = false;
            if ($p['area'] && in_array($p['area'], $areas, true)) {
                $ok = true;
                $shared[Str::title($p['area'])] = true;
            }
            if ($p['coverage'] && in_array($p['coverage'], $coverages, true)) {
                $ok = true;
            }
            if ($p['lat'] !== null) {
                foreach ($stops as $st) {
                    if ($st['lat'] === null) {
                        continue;
                    }
                    $d = $this->km($p['lat'], $p['lng'], $st['lat'], $st['lng']);
                    $nearest = $nearest === null ? $d : min($nearest, $d);
                    if ($d <= self::ROUTE_RADIUS_KM) {
                        $ok = true;
                    }
                }
            }
            if ($ok) {
                $fit++;
            } else {
                $off[] = $p['tracking'];
            }
        }

        return [
            'fit_count' => $fit,
            'shared_areas' => array_keys($shared),
            'nearest_km' => $nearest === null ? null : round($nearest, 1),
            'off_route' => $off,
        ];
    }

    /** Tracking numbers of parcels that do not match any stop already on the run. */
    private function offRoute(DeliveryRun $run, array $shipments): array
    {
        $stops = $this->routeStops($run);

        return $stops === [] ? [] : $this->matchStops($shipments, $stops)['off_route'];
    }

    private function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /** @return array{run: DeliveryRun, removed: list<int>} */
    public function removeItems(DeliveryRun $run, array $shipmentIds, ?string $reason, User $actor): array
    {
        $shipmentIds = $this->ids($shipmentIds);

        return DB::transaction(function () use ($run, $shipmentIds, $reason, $actor) {
            $run = $this->lockRun($run);
            $this->assertEditable($run);

            $items = DeliveryRunItem::query()
                ->where('delivery_run_id', $run->id)
                ->whereIn('shipment_id', $shipmentIds)
                ->where('status', '!=', DeliveryRunItem::STATUS_REMOVED)
                ->get();

            foreach ($items as $item) {
                if ($item->status === DeliveryRunItem::STATUS_BAGGED) {
                    $this->unbagItem($run, $item, $actor, 'Removed from '.$run->run_number.' by '.$actor->name.'.');
                }
                $item->update([
                    'status' => DeliveryRunItem::STATUS_REMOVED,
                    'outcome' => 'removed',
                    'reason' => $reason ?: 'Removed before the run started.',
                    'removed_at' => now(),
                ]);
                $this->trackInternal($item->shipment_id, "Removed from {$run->run_number}.", $actor->id);
            }

            $this->resequence($run);
            $this->recount($run);

            return ['run' => $run->fresh(), 'removed' => $items->pluck('shipment_id')->map(fn ($v) => (int) $v)->values()->all()];
        });
    }

    public function assignRider(DeliveryRun $run, int $riderId, array $meta, User $actor): DeliveryRun
    {
        $this->validateVehicle($meta['vehicle_type'] ?? null);

        $run = DB::transaction(function () use ($run, $riderId, $meta, $actor) {
            $run = $this->lockRun($run);
            $this->assertEditable($run, 'The rider can only be changed before the run starts.');
            $rider = $this->validateRider($riderId, (int) $run->branch_id);
            if ((int) $run->rider_user_id !== (int) $rider->id && empty($meta['allow_second_run'])) {
                $other = $this->unstartedRunFor((int) $rider->id, (int) $run->branch_id, (int) $run->id);
                if ($other) {
                    throw ValidationException::withMessages(['rider_user_id' => [
                        "{$rider->name} already has {$other->run_number} ({$other->expected_count} parcels, not started). Add these parcels to {$other->run_number} instead, or confirm a second run.",
                    ], 'existing_run_id' => [(string) $other->id]]);
                }
            }

            $fill = [
                'rider_user_id' => $rider->id,
                'status' => DeliveryRun::STATUS_ASSIGNED,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
            ];
            foreach (['vehicle_type', 'vehicle_number', 'notes'] as $key) {
                if (array_key_exists($key, $meta) && $meta[$key] !== null && $meta[$key] !== '') {
                    $fill[$key] = $meta[$key];
                }
            }
            $changed = (int) $run->rider_user_id !== (int) $rider->id;
            if ($changed) {
                // Parcels bagged by the previous rider go back on the shelf for the new rider to bag.
                $bagged = DeliveryRunItem::query()->where('delivery_run_id', $run->id)
                    ->where('status', DeliveryRunItem::STATUS_BAGGED)->get();
                foreach ($bagged as $item) {
                    $this->unbagItem($run, $item, $actor, 'Rider changed to '.$rider->name.'; to be bagged again.');
                }
            }
            $run->update($fill);

            return [$run->fresh(), $changed];
        });

        [$fresh, $changed] = $run;
        if ($changed) {
            $this->notifyRider($fresh, 'assigned');
        }

        return $fresh;
    }

    /**
     * Order the stops. mode "auto": by area, city, address. Or an explicit
     * list of item ids (drag order from the board).
     */
    public function sequence(DeliveryRun $run, ?array $itemIds = null): DeliveryRun
    {
        return DB::transaction(function () use ($run, $itemIds) {
            $run = $this->lockRun($run);
            if (in_array($run->status, [DeliveryRun::STATUS_CLOSED, DeliveryRun::STATUS_CANCELLED], true)) {
                throw ValidationException::withMessages(['status' => ['This run is '.$run->status.'.']]);
            }

            if ($itemIds === null || $itemIds === []) {
                $this->autoSequence($run);
            } else {
                $ids = $this->ids($itemIds);
                $valid = DeliveryRunItem::query()->where('delivery_run_id', $run->id)
                    ->where('status', '!=', DeliveryRunItem::STATUS_REMOVED)->pluck('id')->map(fn ($v) => (int) $v)->all();
                $order = array_values(array_intersect($ids, $valid));
                // Anything not listed keeps its order after the listed ones.
                foreach ($valid as $id) {
                    if (! in_array($id, $order, true)) {
                        $order[] = $id;
                    }
                }
                foreach ($order as $i => $id) {
                    DeliveryRunItem::query()->whereKey($id)->update(['sequence' => $i + 1]);
                }
            }

            return $run->fresh();
        });
    }

    /* ------------------------------------------------------------------ */
    /* Dispatch                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Admin override (super / main admin only, enforced in the controller):
     * bag every queued parcel for the rider and start the run in one go. The
     * normal path is the rider bagging each parcel and tapping Start.
     */
    public function dispatch(DeliveryRun $run, User $actor, array $meta = []): DeliveryRun
    {
        $this->validateVehicle($meta['vehicle_type'] ?? null);

        return $this->startRun($run, $actor, false, $meta);
    }

    /** Rider leaves the branch. Every parcel on the run must be bagged first. */
    public function startByRider(DeliveryRun $run, User $rider): DeliveryRun
    {
        $this->assertRunRider($run, $rider);

        return $this->startRun($run, $rider, true);
    }

    /**
     * Rider scans / taps a parcel at the warehouse: its delivery is assigned to
     * the rider and accepted through DeliveryWorkflowService (same tracking,
     * webhooks and store callbacks as the single-delivery flow; callbacks are
     * queued after commit). The stop becomes "bagged".
     *
     * @return array{run: DeliveryRun, item: DeliveryRunItem, already: bool, tracking: string}
     */
    public function bag(DeliveryRun $run, User $rider, ?int $itemId = null, ?string $code = null): array
    {
        $this->assertRunRider($run, $rider);

        return DB::transaction(function () use ($run, $rider, $itemId, $code) {
            $run = $this->lockRun($run);
            $this->assertCollecting($run);
            $item = $this->findRunItem($run, $itemId, $code);
            $tracking = $this->itemLabel($item);
            if ($item->status === DeliveryRunItem::STATUS_BAGGED) {
                return ['run' => $run->fresh(), 'item' => $item, 'already' => true, 'tracking' => $tracking];
            }
            if ($item->status !== DeliveryRunItem::STATUS_QUEUED) {
                throw ValidationException::withMessages(['code' => ["{$tracking} cannot be bagged ({$item->status})."]]);
            }
            $this->bagItem($run, $item, $this->validateRider((int) $run->rider_user_id, (int) $run->branch_id), $rider);
            $this->recount($run);

            return ['run' => $run->fresh(), 'item' => $item->fresh(), 'already' => false, 'tracking' => $tracking];
        });
    }

    /** Rider takes a parcel back out of the bag (before the run starts). */
    public function unbag(DeliveryRun $run, User $rider, int $itemId): array
    {
        $this->assertRunRider($run, $rider);

        return DB::transaction(function () use ($run, $rider, $itemId) {
            $run = $this->lockRun($run);
            $this->assertCollecting($run);
            $item = $this->findRunItem($run, $itemId, null);
            if ($item->status === DeliveryRunItem::STATUS_BAGGED) {
                $this->unbagItem($run, $item, $rider, 'Taken out of the bag by '.$rider->name.'.');
                $this->recount($run);
            }

            return ['run' => $run->fresh(), 'item' => $item->fresh(), 'tracking' => $this->itemLabel($item)];
        });
    }

    /**
     * Rider cannot find a parcel at the warehouse: it leaves the run, goes back
     * to Ready for delivery (sorted for delivery) and the branch is told.
     */
    public function reportMissing(DeliveryRun $run, User $rider, int $itemId, ?string $note = null): array
    {
        $this->assertRunRider($run, $rider);

        $out = DB::transaction(function () use ($run, $rider, $itemId, $note) {
            $run = $this->lockRun($run);
            $this->assertCollecting($run);
            $item = $this->findRunItem($run, $itemId, null);
            $tracking = $this->itemLabel($item);
            if (! in_array($item->status, [DeliveryRunItem::STATUS_QUEUED, DeliveryRunItem::STATUS_BAGGED], true)) {
                throw ValidationException::withMessages(['item_id' => ["{$tracking} is {$item->status}."]]);
            }
            $note = trim((string) $note);
            $reason = 'Not found at the warehouse by '.$rider->name.($note !== '' ? ': '.$note : '.');
            if ($item->status === DeliveryRunItem::STATUS_BAGGED) {
                $this->unbagItem($run, $item, $rider, $reason);
            }
            $item->update([
                'status' => DeliveryRunItem::STATUS_REMOVED,
                'outcome' => 'missing',
                'reason' => mb_substr($reason, 0, 250),
                'removed_at' => now(),
            ]);
            $this->trackInternal((int) $item->shipment_id, "Removed from {$run->run_number}. {$reason} Back in Ready for delivery.", $rider->id);
            $this->resequence($run);
            $this->recount($run);

            return ['run' => $run->fresh(), 'item' => $item->fresh(), 'tracking' => $tracking, 'reason' => $reason];
        });

        $this->notifyBranch(
            $out['run'],
            "{$out['tracking']} missing on {$out['run']->run_number}",
            "{$rider->name} could not find {$out['tracking']} at the warehouse. It was removed from {$out['run']->run_number} and is back in Ready for delivery. {$out['reason']}",
            ['shipment_id' => (int) $out['item']->shipment_id, 'reported_by' => $rider->id, 'event' => 'missing']
        );

        return $out;
    }

    /**
     * Start the run: every bagged parcel goes out for delivery. All or nothing.
     * $requireBagged: rider path (422 listing unbagged parcels). Admin override
     * bags the queued parcels first.
     */
    private function startRun(DeliveryRun $run, User $actor, bool $requireBagged, array $meta = []): DeliveryRun
    {
        $run = DB::transaction(function () use ($run, $actor, $requireBagged, $meta) {
            $run = $this->lockRun($run);
            foreach (['vehicle_type', 'vehicle_number'] as $key) {
                if (! empty($meta[$key])) {
                    $run->{$key} = $meta[$key];
                }
            }
            if ($run->isDirty()) {
                $run->save();
            }

            if ($run->status === DeliveryRun::STATUS_OPEN) {
                throw ValidationException::withMessages(['rider_user_id' => ['Assign a rider before the run can start.']]);
            }
            if ($run->status !== DeliveryRun::STATUS_ASSIGNED) {
                throw ValidationException::withMessages(['status' => [$this->statusMessage($run)]]);
            }

            $rider = $this->validateRider((int) $run->rider_user_id, (int) $run->branch_id);
            $items = DeliveryRunItem::query()->where('delivery_run_id', $run->id)
                ->whereIn('status', [DeliveryRunItem::STATUS_QUEUED, DeliveryRunItem::STATUS_BAGGED])
                ->orderBy('sequence')->orderBy('id')->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => ['The run has no parcels. Add parcels or cancel it.']]);
            }

            $unbagged = $items->where('status', DeliveryRunItem::STATUS_QUEUED)->values();
            if ($requireBagged && $unbagged->isNotEmpty()) {
                $tracking = Shipment::query()->whereIn('id', $unbagged->pluck('shipment_id'))->pluck('tracking_number', 'id');
                $list = $unbagged->map(fn (DeliveryRunItem $i) => '#'.$i->sequence.' '.($tracking[$i->shipment_id] ?? $i->shipment_id))->implode(', ');
                throw ValidationException::withMessages([
                    'items' => [$unbagged->count().' of '.$items->count().' parcel'.($items->count() === 1 ? '' : 's')." not bagged yet: {$list}. Bag them or report them missing before starting."],
                ]);
            }

            foreach ($items as $item) {
                if ($item->status === DeliveryRunItem::STATUS_QUEUED) {
                    $this->bagItem($run, $item, $rider, $actor);
                    $item->refresh();
                }
                $this->sendOut($run, $item, $rider, $actor);
            }

            $fill = [
                'status' => DeliveryRun::STATUS_OUT,
                'dispatched_at' => now(),
                'dispatched_by' => $actor->id,
            ];
            if ($this->hasCol('delivery_runs', 'started_at')) {
                $fill['started_at'] = now();
            }
            $run->update($fill);
            $this->recount($run);

            return $run->fresh();
        });

        $count = (int) $run->expected_count;
        $this->notifyBranch(
            $run,
            "{$run->run_number} left the branch",
            ($this->userName((int) $run->rider_user_id) ?: 'The rider')." started {$run->run_number} with {$count} parcel".($count === 1 ? '' : 's').' (COD Rs '.number_format((float) $run->cod_expected, 2).').',
            ['event' => 'started', 'started_by' => $actor->id]
        );

        return $run;
    }

    /** Assign + accept the parcel's delivery for the run rider and mark the stop bagged. */
    private function bagItem(DeliveryRun $run, DeliveryRunItem $item, User $rider, User $actor): void
    {
        $shipment = Shipment::query()->lockForUpdate()->findOrFail($item->shipment_id);
        try {
            $delivery = $this->deliveryForHandover($shipment, $actor);
            if ($delivery->status === 'pending'
                || ($delivery->status === 'assigned' && (int) $delivery->rider_id !== (int) $rider->id)) {
                $this->workflow->assign($delivery, $rider, $actor, true);
            }
            $delivery->refresh();
            if ($delivery->status === 'assigned') {
                $this->workflow->accept($delivery, $rider);
            }
            $delivery->refresh();
            if ($delivery->status !== 'accepted' || (int) $delivery->rider_id !== (int) $rider->id) {
                throw ValidationException::withMessages(['status' => ["delivery is {$delivery->status}."]]);
            }
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first();
            throw ValidationException::withMessages(['items' => [$shipment->tracking_number.': '.($first ?: 'cannot be bagged.')]]);
        }

        $fill = [
            'delivery_id' => $delivery->id,
            'attempt' => (int) ($delivery->attempt_no ?: $item->attempt ?: 1),
            'status' => DeliveryRunItem::STATUS_BAGGED,
            'cod_amount' => $this->collectable($shipment),
        ];
        if ($this->hasCol('delivery_run_items', 'bagged_at')) {
            $fill['bagged_at'] = now();
            $fill['bagged_by'] = $actor->id;
        }
        $item->update($fill);
        $this->trackInternal($shipment->id, "Bagged for {$run->run_number} by {$actor->name}.", $actor->id);
    }

    /** Bagged parcel -> out for delivery (run start). */
    private function sendOut(DeliveryRun $run, DeliveryRunItem $item, User $rider, User $actor): void
    {
        $shipment = Shipment::query()->lockForUpdate()->findOrFail($item->shipment_id);
        $delivery = $item->delivery_id ? DeliveryAssignment::query()->find($item->delivery_id) : null;
        try {
            if (! $delivery || ! in_array($delivery->status, ['assigned', 'accepted', 'out_for_delivery'], true)
                || (int) $delivery->rider_id !== (int) $rider->id) {
                throw ValidationException::withMessages(['status' => ['is no longer bagged for this rider ('.($delivery?->status ?? 'no delivery').'). Bag it again.']]);
            }
            if (in_array($delivery->status, ['assigned', 'accepted'], true)) {
                $this->workflow->outForDelivery($delivery, $rider, true);
            }
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first();
            throw ValidationException::withMessages(['items' => [$shipment->tracking_number.': '.($first ?: 'cannot be sent out.')]]);
        }

        $item->update([
            'status' => DeliveryRunItem::STATUS_OUT,
            'cod_amount' => $this->collectable($shipment),
        ]);
        $this->trackInternal($shipment->id, "Out on {$run->run_number} with {$rider->name}.", $actor->id);
    }

    /**
     * Undo a bag: the delivery goes back to pending (no rider), the parcel back
     * to sorted for delivery (branch shelf) and the stop back to queued.
     */
    private function unbagItem(DeliveryRun $run, DeliveryRunItem $item, User $actor, string $reason): void
    {
        $delivery = $item->delivery_id ? DeliveryAssignment::query()->lockForUpdate()->find($item->delivery_id) : null;
        if ($delivery && in_array($delivery->status, ['assigned', 'accepted'], true)) {
            $delivery->update([
                'status' => 'pending',
                'rider_id' => null,
                'delivery_staff_id' => null,
                'assigned_at' => null,
                'accepted_at' => null,
            ]);
        }

        $shipment = Shipment::query()->lockForUpdate()->find($item->shipment_id);
        if ($shipment && $shipment->status === CourierStatus::ASSIGNED_TO_RIDER) {
            $shipment->update([
                'status' => CourierStatus::SORTED_FOR_DELIVERY,
                'merchant_status' => CourierStatus::merchantStatus(CourierStatus::SORTED_FOR_DELIVERY),
            ]);
            $this->tracking->record($shipment->fresh(), CourierStatus::SORTED_FOR_DELIVERY, 'Back at the branch for delivery. '.$reason, $actor->id);
            app(ShipmentCallbackService::class)->sortedForDelivery($shipment->fresh());
        }

        $fill = ['status' => DeliveryRunItem::STATUS_QUEUED];
        if ($this->hasCol('delivery_run_items', 'bagged_at')) {
            $fill['bagged_at'] = null;
            $fill['bagged_by'] = null;
        }
        $item->update($fill);
        $this->trackInternal((int) $item->shipment_id, "Unbagged on {$run->run_number}. {$reason}", $actor->id);
    }

    private function assertRunRider(DeliveryRun $run, User $rider): void
    {
        if ((int) $run->rider_user_id !== (int) $rider->id) {
            abort(403, 'This run is assigned to another rider.');
        }
    }

    private function assertCollecting(DeliveryRun $run): void
    {
        if ($run->status !== DeliveryRun::STATUS_ASSIGNED) {
            throw ValidationException::withMessages(['status' => [
                $run->status === DeliveryRun::STATUS_OUT ? "{$run->run_number} has already started." : $this->statusMessage($run),
            ]]);
        }
    }

    /** Stop on the run by item id, or by scanned / typed tracking number. */
    private function findRunItem(DeliveryRun $run, ?int $itemId, ?string $code): DeliveryRunItem
    {
        $q = DeliveryRunItem::query()->where('delivery_run_id', $run->id)
            ->where('status', '!=', DeliveryRunItem::STATUS_REMOVED);
        if ($itemId) {
            $item = (clone $q)->whereKey($itemId)->lockForUpdate()->first();
            if (! $item) {
                throw ValidationException::withMessages(['item_id' => ["That stop is not on {$run->run_number}."]]);
            }

            return $item;
        }

        $raw = strtoupper((string) preg_replace('/\s+/', '', (string) $code));
        if ($raw === '') {
            throw ValidationException::withMessages(['code' => ['Scan or type a tracking number.']]);
        }
        $shipment = Shipment::query()->where('tracking_number', $raw)->first(['id', 'tracking_number']);
        if (! $shipment) {
            throw ValidationException::withMessages(['code' => ["No parcel with tracking number {$raw}. Check the label."]]);
        }
        $item = (clone $q)->where('shipment_id', $shipment->id)->lockForUpdate()->first();
        if (! $item) {
            $other = $this->activeRunFor((int) $shipment->id);
            throw ValidationException::withMessages(['code' => [
                $other && (int) $other->id !== (int) $run->id
                    ? "{$shipment->tracking_number} is on {$other->run_number}, not {$run->run_number}. Do not take it."
                    : "{$shipment->tracking_number} is not on {$run->run_number}. Do not take it.",
            ]]);
        }

        return $item;
    }

    private function itemLabel(DeliveryRunItem $item): string
    {
        return (string) (Shipment::query()->whereKey($item->shipment_id)->value('tracking_number') ?: 'Parcel #'.$item->shipment_id);
    }

    private function hasCol(string $table, string $column): bool
    {
        static $cache = [];

        return $cache[$table.'.'.$column] ??= Schema::hasColumn($table, $column);
    }

    /**
     * Rider finishes the run from the rider app once every stop is delivered,
     * failed or removed. Uses the same completion path as auto-complete
     * (recount + maybeComplete) and tells the branch the run is ready to close.
     * Idempotent: finishing an already completed run just re-notifies.
     */
    public function finishByRider(DeliveryRun $run, User $rider): DeliveryRun
    {
        if ((int) $run->rider_user_id !== (int) $rider->id) {
            abort(403, 'This run is assigned to another rider.');
        }

        return DB::transaction(function () use ($run, $rider) {
            $run = $this->lockRun($run);
            if (! in_array($run->status, [DeliveryRun::STATUS_OUT, DeliveryRun::STATUS_COMPLETED], true)) {
                throw ValidationException::withMessages(['status' => [$this->statusMessage($run)]]);
            }

            $items = DeliveryRunItem::query()->where('delivery_run_id', $run->id)
                ->where('status', '!=', DeliveryRunItem::STATUS_REMOVED)->orderBy('sequence')->get();
            foreach ($items as $item) {
                $this->applyDeliveryState($item);
            }
            $items = $items->map(fn (DeliveryRunItem $i) => $i->fresh());
            $open = $items->filter(fn (DeliveryRunItem $i) => ! in_array($i->status, DeliveryRunItem::TERMINAL_STATUSES, true));
            if ($open->isNotEmpty()) {
                $tracking = Shipment::query()->whereIn('id', $open->pluck('shipment_id'))->pluck('tracking_number', 'id');
                $list = $open->map(fn (DeliveryRunItem $i) => '#'.$i->sequence.' '.($tracking[$i->shipment_id] ?? ('shipment '.$i->shipment_id)))->implode(', ');
                throw ValidationException::withMessages(['items' => [
                    $open->count().' stop'.($open->count() === 1 ? '' : 's')." still open: {$list}. Mark each delivered or failed before finishing the run.",
                ]]);
            }

            $wasCompleted = $run->status === DeliveryRun::STATUS_COMPLETED;
            $this->recount($run);
            $this->maybeComplete($run);
            $run->refresh();
            if ($run->status !== DeliveryRun::STATUS_COMPLETED) {
                $run->update(['status' => DeliveryRun::STATUS_COMPLETED, 'completed_at' => $run->completed_at ?? now()]);
            }
            if ($wasCompleted) {
                $this->notifyBranch($run, "{$run->run_number} finished by rider",
                    sprintf('%s finished %s: %d delivered, %d failed. Close the run to check cash (Rs %s) and requeue failed parcels.',
                        $rider->name, $run->run_number, $run->delivered_count, $run->failed_count, number_format((float) $run->cod_collected, 2)),
                    ['finished_by' => $rider->id]);
            }
            Log::info('delivery_run.finished_by_rider', ['run_id' => $run->id, 'rider_id' => $rider->id]);

            return $run->fresh();
        });
    }

    /* ------------------------------------------------------------------ */
    /* Outcomes                                                            */
    /* ------------------------------------------------------------------ */

    /** Called by DeliveryWorkflowService after arrived / delivered / failed. */
    public function syncFromDelivery(int $deliveryId): void
    {
        if (! Schema::hasTable('delivery_run_items')) {
            return;
        }

        $item = DeliveryRunItem::query()
            ->where('delivery_id', $deliveryId)
            ->where('status', '!=', DeliveryRunItem::STATUS_REMOVED)
            ->whereHas('run', fn ($q) => $q->whereIn('status', [DeliveryRun::STATUS_OUT, DeliveryRun::STATUS_COMPLETED]))
            ->latest('id')
            ->first();
        if (! $item) {
            return;
        }

        $this->applyDeliveryState($item);
        $run = $item->run;
        $this->recount($run);
        $this->maybeComplete($run);
    }

    /**
     * Close the run once the rider is back.
     *
     * @param  array{cash_received?:?float, deposit_cash?:bool, default_action?:string, items?:list<array{item_id:int,action:string,reason?:?string}>, notes?:?string}  $data
     */
    public function close(DeliveryRun $run, array $data, User $actor): DeliveryRun
    {
        return DB::transaction(function () use ($run, $data, $actor) {
            $run = $this->lockRun($run);
            if (! in_array($run->status, [DeliveryRun::STATUS_OUT, DeliveryRun::STATUS_COMPLETED], true)) {
                throw ValidationException::withMessages(['status' => [$this->statusMessage($run)]]);
            }

            $defaultAction = ($data['default_action'] ?? 'reattempt') === 'return' ? 'return' : 'reattempt';
            $choices = [];
            foreach (($data['items'] ?? []) as $row) {
                $action = (string) ($row['action'] ?? '');
                if (! in_array($action, ['reattempt', 'return'], true)) {
                    throw ValidationException::withMessages(['items' => ['Action must be reattempt or return.']]);
                }
                $choices[(int) ($row['item_id'] ?? 0)] = ['action' => $action, 'reason' => $row['reason'] ?? null];
            }

            $items = DeliveryRunItem::query()->where('delivery_run_id', $run->id)
                ->where('status', '!=', DeliveryRunItem::STATUS_REMOVED)->orderBy('sequence')->get();

            // 1) Parcels still with the rider come back undelivered.
            foreach ($items as $item) {
                $this->applyDeliveryState($item);
                if ($item->status !== DeliveryRunItem::STATUS_OUT) {
                    continue;
                }
                $reason = trim((string) ($choices[$item->id]['reason'] ?? '')) ?: 'Not delivered on '.$run->run_number.'; returned to branch.';
                $delivery = $item->delivery_id ? DeliveryAssignment::query()->find($item->delivery_id) : null;
                if ($delivery && ! in_array($delivery->status, ['delivered', 'failed', 'cancelled'], true)) {
                    $this->workflow->failed($delivery, $actor, $reason, true);
                }
                $item->refresh();
                $this->applyDeliveryState($item);
            }

            // 2) Failed parcels: re-delivery queue or return to merchant.
            foreach ($items as $item) {
                $item->refresh();
                if ($item->status !== DeliveryRunItem::STATUS_FAILED || in_array($item->outcome, ['reattempt', 'returned'], true)) {
                    continue;
                }
                $action = $choices[$item->id]['action'] ?? $defaultAction;
                $shipment = Shipment::query()->lockForUpdate()->find($item->shipment_id);
                if (! $shipment) {
                    continue;
                }
                if ($action === 'return') {
                    $this->initiateReturn($shipment, $run, $item, $actor);
                    $item->update(['outcome' => 'returned']);
                } else {
                    $this->requeue($shipment, $run, $item, $actor);
                    $item->update(['outcome' => 'reattempt']);
                }
            }

            $this->recount($run);
            $run->refresh();

            // 3) Cash handover: compare with cash POD collected on this run.
            $cashExpected = round((float) $run->cod_collected, 2);
            $cashReceived = array_key_exists('cash_received', $data) && $data['cash_received'] !== null && $data['cash_received'] !== ''
                ? round((float) $data['cash_received'], 2)
                : $cashExpected;
            if ($cashReceived < 0) {
                throw ValidationException::withMessages(['cash_received' => ['Cash received cannot be negative.']]);
            }
            $variance = round($cashReceived - $cashExpected, 2);
            $notes = trim((string) ($data['notes'] ?? ''));
            if (abs($variance) >= 0.01 && $notes === '') {
                throw ValidationException::withMessages(['notes' => [sprintf(
                    'Cash received (Rs %s) differs from cash POD collected (Rs %s). Add a note explaining the difference.',
                    number_format($cashReceived, 2), number_format($cashExpected, 2)
                )]]);
            }

            $depositId = null;
            $deposited = false;
            if (($data['deposit_cash'] ?? true) !== false) {
                $depositId = $this->depositCash($run, $items, $actor);
                $deposited = $depositId !== null;
            }

            $run->update([
                'status' => DeliveryRun::STATUS_CLOSED,
                'cash_received' => $cashReceived,
                'cash_variance' => $variance,
                'cash_deposited' => $deposited,
                'pod_deposit_id' => $depositId,
                'close_notes' => $notes !== '' ? $notes : null,
                'closed_by' => $actor->id,
                'closed_at' => now(),
                'completed_at' => $run->completed_at ?? now(),
            ]);

            return $run->fresh();
        });
    }

    public function cancel(DeliveryRun $run, ?string $reason, User $actor): DeliveryRun
    {
        return DB::transaction(function () use ($run, $reason, $actor) {
            $run = $this->lockRun($run);
            $this->assertEditable($run, 'Only a run that has not started can be cancelled.');

            $items = DeliveryRunItem::query()->where('delivery_run_id', $run->id)
                ->where('status', '!=', DeliveryRunItem::STATUS_REMOVED)->get();
            foreach ($items as $item) {
                if ($item->status === DeliveryRunItem::STATUS_BAGGED) {
                    $this->unbagItem($run, $item, $actor, $run->run_number.' cancelled.');
                }
                $item->update(['status' => DeliveryRunItem::STATUS_REMOVED, 'outcome' => 'removed', 'reason' => 'Run cancelled.', 'removed_at' => now()]);
            }

            $run->update([
                'status' => DeliveryRun::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => $reason,
            ]);
            $this->recount($run);
            if ($run->rider_user_id) {
                $this->notifyRider($run->fresh(), 'cancelled');
            }

            return $run->fresh();
        });
    }

    /* ------------------------------------------------------------------ */
    /* Riders                                                              */
    /* ------------------------------------------------------------------ */

    /** Active riders / staff of the branch (riders first, least busy first). */
    public function riders(int $branchId): Collection
    {
        $q = User::query()->where('is_active', true)
            ->where(function ($w) {
                $w->whereIn('role', self::RIDER_ROLES)
                    ->orWhereHas('roles', fn ($r) => $r->whereIn('name', self::RIDER_ROLES));
            });
        if ($branchId > 0) {
            $q->whereIn('branch_id', $this->branchFamily($branchId));
        } else {
            $q->whereNotNull('branch_id');
        }

        $users = $q->orderBy('name')->limit(200)->get(['id', 'name', 'phone', 'role', 'branch_id']);
        $runCounts = DeliveryRun::query()->whereIn('rider_user_id', $users->pluck('id'))
            ->whereIn('status', [DeliveryRun::STATUS_ASSIGNED, DeliveryRun::STATUS_OUT, DeliveryRun::STATUS_COMPLETED])
            ->selectRaw('rider_user_id, COUNT(*) c')->groupBy('rider_user_id')->pluck('c', 'rider_user_id');
        $deliveryCounts = DeliveryAssignment::query()->whereIn('rider_id', $users->pluck('id'))
            ->whereIn('status', ['assigned', 'accepted', 'out_for_delivery'])
            ->selectRaw('rider_id, COUNT(*) c')->groupBy('rider_id')->pluck('c', 'rider_id');

        return $users->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'phone' => $u->phone,
            'role' => $u->role,
            'branch_id' => $u->branch_id,
            'is_rider' => in_array($u->role, ['rider', 'delivery_staff', 'pickup_rider'], true),
            'active_runs' => (int) ($runCounts[$u->id] ?? 0),
            'active_deliveries' => (int) ($deliveryCounts[$u->id] ?? 0),
        ])->sortBy([['is_rider', 'desc'], ['active_deliveries', 'asc'], ['name', 'asc']])->values();
    }

    public function validateRider(int $riderId, int $branchId): User
    {
        $rider = User::query()->find($riderId);
        if (! $rider) {
            throw ValidationException::withMessages(['rider_user_id' => ['Rider not found.']]);
        }
        if (! $rider->is_active) {
            throw ValidationException::withMessages(['rider_user_id' => [$rider->name.' is not active.']]);
        }
        $roleOk = in_array((string) $rider->role, self::RIDER_ROLES, true);
        if (! $roleOk) {
            try {
                $roleOk = $rider->hasAnyRole(self::RIDER_ROLES);
            } catch (\Throwable) {
                $roleOk = false;
            }
        }
        if (! $roleOk) {
            throw ValidationException::withMessages(['rider_user_id' => [$rider->name.' is not a rider or branch staff.']]);
        }
        if (! in_array((int) $rider->branch_id, $this->branchFamily($branchId), true)) {
            throw ValidationException::withMessages(['rider_user_id' => [
                $rider->name.' belongs to '.$this->branchName((int) $rider->branch_id).', not '.$this->branchName($branchId).'.',
            ]]);
        }

        return $rider;
    }

    /* ------------------------------------------------------------------ */
    /* Presentation                                                        */
    /* ------------------------------------------------------------------ */

    public function summarize(DeliveryRun $run, bool $withItems = false): array
    {
        $run->loadMissing(['branch:id,name,code,phone,address,latitude,longitude', 'rider:id,name,phone']);
        $out = [
            'id' => $run->id,
            'run_number' => $run->run_number,
            'display_number' => $run->run_number,
            'status' => $run->status,
            'branch' => $run->branch ? [
                'id' => $run->branch->id,
                'name' => $run->branch->name,
                'phone' => $run->branch->phone,
                'address' => $run->branch->address,
                'latitude' => $run->branch->latitude ? (float) $run->branch->latitude : null,
                'longitude' => $run->branch->longitude ? (float) $run->branch->longitude : null,
            ] : null,
            'rider' => $run->rider ? ['id' => $run->rider->id, 'name' => $run->rider->name, 'phone' => $run->rider->phone] : null,
            'vehicle_type' => $run->vehicle_type,
            'vehicle_number' => $run->vehicle_number,
            'area_label' => $run->area_label,
            'notes' => $run->notes,
            'expected_count' => (int) $run->expected_count,
            'delivered_count' => (int) $run->delivered_count,
            'failed_count' => (int) $run->failed_count,
            'returned_count' => (int) $run->returned_count,
            'reattempt_count' => (int) $run->reattempt_count,
            'pending_count' => max(0, (int) $run->expected_count - (int) $run->delivered_count - (int) $run->failed_count),
            'cod_expected' => $this->liveCodExpected($run),
            'cod_collected' => (float) $run->cod_collected,
            'online_collected' => (float) $run->online_collected,
            'cash_received' => $run->cash_received,
            'cash_variance' => $run->cash_variance,
            'cash_deposited' => (bool) $run->cash_deposited,
            'pod_deposit_id' => $run->pod_deposit_id,
            'close_notes' => $run->close_notes,
            'cancel_reason' => $run->cancel_reason,
            'created_by' => $run->created_by ? ['id' => $run->created_by, 'name' => $this->userName((int) $run->created_by)] : null,
            'closed_by' => $run->closed_by ? ['id' => $run->closed_by, 'name' => $this->userName((int) $run->closed_by)] : null,
            'created_at' => $run->created_at?->toIso8601String(),
            'assigned_at' => $run->assigned_at?->toIso8601String(),
            'dispatched_at' => $run->dispatched_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'closed_at' => $run->closed_at?->toIso8601String(),
            'cancelled_at' => $run->cancelled_at?->toIso8601String(),
            'editable' => $run->isEditable(),
        ];
        $out['started_at'] = $this->hasCol('delivery_runs', 'started_at') && $run->started_at
            ? \Illuminate\Support\Carbon::parse($run->started_at)->toIso8601String()
            : $out['dispatched_at'];
        if (in_array($run->status, [DeliveryRun::STATUS_OPEN, DeliveryRun::STATUS_ASSIGNED], true)) {
            $counts = DeliveryRunItem::query()->where('delivery_run_id', $run->id)
                ->whereIn('status', [DeliveryRunItem::STATUS_QUEUED, DeliveryRunItem::STATUS_BAGGED])
                ->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
            $out['bagged_count'] = (int) ($counts[DeliveryRunItem::STATUS_BAGGED] ?? 0);
            $out['to_bag_count'] = (int) ($counts[DeliveryRunItem::STATUS_QUEUED] ?? 0);
        } else {
            $out['bagged_count'] = in_array($run->status, [DeliveryRun::STATUS_CANCELLED], true) ? 0 : (int) $run->expected_count;
            $out['to_bag_count'] = 0;
        }
        $out['new_count'] = $run->status === DeliveryRun::STATUS_ASSIGNED && $this->hasCol('delivery_run_items', 'late_added_at')
            ? (int) DeliveryRunItem::query()->where('delivery_run_id', $run->id)->where('status', DeliveryRunItem::STATUS_QUEUED)->whereNotNull('late_added_at')->count()
            : 0;
        $out['can_start'] = $run->status === DeliveryRun::STATUS_ASSIGNED && $out['bagged_count'] > 0 && $out['to_bag_count'] === 0;

        if ($withItems) {
            $items = DeliveryRunItem::query()->where('delivery_run_id', $run->id)
                ->with(['shipment.merchant:id,name', 'delivery'])
                ->orderByRaw("CASE WHEN status = 'removed' THEN 1 ELSE 0 END")
                ->orderBy('sequence')->orderBy('id')->get();
            $out['items'] = $items->map(fn (DeliveryRunItem $i) => $this->itemRow($i))->values()->all();
        }

        return $out;
    }

    public function itemRow(DeliveryRunItem $item): array
    {
        $s = $item->shipment;

        return array_merge($s ? $this->shipmentRow($s) : ['shipment_id' => $item->shipment_id], [
            'id' => $item->id,
            'item_id' => $item->id,
            'shipment_id' => $item->shipment_id,
            'delivery_id' => $item->delivery_id,
            'delivery_status' => $item->delivery?->status,
            'arrived' => (bool) ($item->arrived_at ?? $item->delivery?->arrived_at),
            'sequence' => (int) $item->sequence,
            'status' => $item->status,
            'outcome' => $item->outcome,
            'attempt' => (int) $item->attempt,
            'reason' => $item->reason,
            'cod_amount' => $this->itemCod($item),
            'collected_amount' => (float) $item->collected_amount,
            'payment_method' => $item->payment_method,
            'delivered_at' => $item->delivered_at?->toIso8601String(),
            'failed_at' => $item->failed_at?->toIso8601String(),
            'late_added_at' => $this->hasCol('delivery_run_items', 'late_added_at') && $item->late_added_at ? \Illuminate\Support\Carbon::parse($item->late_added_at)->toIso8601String() : null,
            'is_new' => $this->hasCol('delivery_run_items', 'late_added_at') && $item->late_added_at && $item->status === DeliveryRunItem::STATUS_QUEUED,
            'bagged_at' => $this->hasCol('delivery_run_items', 'bagged_at') && $item->bagged_at ? \Illuminate\Support\Carbon::parse($item->bagged_at)->toIso8601String() : null,
        ]);
    }

    /** Active run for a parcel (open .. completed), if any. */
    public function activeRunFor(int $shipmentId): ?DeliveryRun
    {
        if (! Schema::hasTable('delivery_run_items')) {
            return null;
        }
        $runId = DB::table('delivery_run_items as ri')
            ->join('delivery_runs as r', 'r.id', '=', 'ri.delivery_run_id')
            ->where('ri.shipment_id', $shipmentId)
            ->where('ri.status', '!=', DeliveryRunItem::STATUS_REMOVED)
            ->whereIn('r.status', DeliveryRun::ACTIVE_STATUSES)
            ->value('r.id');

        return $runId ? DeliveryRun::query()->find($runId) : null;
    }

    /**
     * Adds `delivery_run` {id, run_number, status, sequence, item_status, outcome}
     * to each delivery assignment (null when the parcel was never on a run).
     * Matches the run item by delivery id, or by shipment for a run that is
     * still active. One query for the whole page.
     *
     * @param  iterable<\Modules\Delivery\Models\DeliveryAssignment>  $deliveries
     */
    public function attachRunInfo(iterable $deliveries): void
    {
        $list = collect($deliveries)->filter();
        if ($list->isEmpty()) {
            return;
        }
        $deliveryIds = $list->pluck('id')->map(fn ($v) => (int) $v)->filter()->unique()->values()->all();
        $shipmentIds = $list->pluck('shipment_id')->map(fn ($v) => (int) $v)->filter()->unique()->values()->all();

        $byDelivery = [];
        $byShipment = [];
        if (Schema::hasTable('delivery_run_items')) {
            $rows = DB::table('delivery_run_items as ri')
                ->join('delivery_runs as r', 'r.id', '=', 'ri.delivery_run_id')
                ->leftJoin('users as ru', 'ru.id', '=', 'r.rider_user_id')
                ->where('ri.status', '!=', DeliveryRunItem::STATUS_REMOVED)
                ->where(function ($w) use ($deliveryIds, $shipmentIds) {
                    $w->whereIn('ri.delivery_id', $deliveryIds ?: [0])
                        ->orWhere(function ($a) use ($shipmentIds) {
                            $a->whereIn('ri.shipment_id', $shipmentIds ?: [0])
                                ->whereIn('r.status', DeliveryRun::ACTIVE_STATUSES);
                        });
                })
                ->orderBy('ri.id')
                ->get([
                    'ri.delivery_id', 'ri.shipment_id', 'ri.sequence', 'ri.status as item_status', 'ri.outcome',
                    'r.id as run_id', 'r.run_number', 'r.status as run_status',
                    'r.rider_user_id', 'ru.name as rider_name', 'ru.phone as rider_phone',
                ]);

            foreach ($rows as $row) {
                $info = [
                    'id' => (int) $row->run_id,
                    'run_number' => $row->run_number,
                    'status' => $row->run_status,
                    'active' => in_array($row->run_status, DeliveryRun::ACTIVE_STATUSES, true),
                    'sequence' => (int) $row->sequence,
                    'item_status' => $row->item_status,
                    'outcome' => $row->outcome,
                    'rider' => $row->rider_user_id
                        ? ['id' => (int) $row->rider_user_id, 'name' => $row->rider_name, 'phone' => $row->rider_phone]
                        : null,
                ];
                // Later items win: the newest run for that delivery / parcel.
                if ($row->delivery_id) {
                    $byDelivery[(int) $row->delivery_id] = $info;
                }
                if ($info['active']) {
                    $byShipment[(int) $row->shipment_id] = $info;
                }
            }
        }

        foreach ($list as $delivery) {
            $delivery->setAttribute(
                'delivery_run',
                $byDelivery[(int) $delivery->id] ?? $byShipment[(int) $delivery->shipment_id] ?? null
            );
        }
    }

    public function findByNumber(string $raw, int $branchId = 0): ?DeliveryRun
    {
        $s = strtoupper(preg_replace('/\s+/', '', $raw) ?? '');
        if ($s === '') {
            return null;
        }
        $q = DeliveryRun::query()->when($branchId > 0, fn ($w) => $w->whereIn('branch_id', $this->branchFamily($branchId)));
        $exact = (clone $q)->where('run_number', $s)->first();
        if ($exact) {
            return $exact;
        }

        if (preg_match('/^(?:(?:DR-RUN|DR)-?)?0*(\d{1,9})$/', $s, $m)) {
            $num = (int) $m[1];
            $candidates = [
                sprintf('DR-RUN-%03d', $num),
                sprintf('DR-RUN-%06d', $num),
                sprintf('DR-RUN-%d', $num),
            ];
            $found = (clone $q)->whereIn('run_number', $candidates)->first();
            if ($found) {
                return $found;
            }

            $suffix3 = '-'.str_pad((string) $num, 3, '0', STR_PAD_LEFT);
            $suffix6 = '-'.str_pad((string) $num, 6, '0', STR_PAD_LEFT);

            return $q->where(fn ($w) => $w->where('run_number', 'like', 'DR-%'.$suffix3)->orWhere('run_number', 'like', 'DR-%'.$suffix6))->latest('id')->first();
        }

        return $q->where('run_number', $s)->first();
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Split requested parcels into eligible ones and skipped ones with a reason.
     *
     * @return array{0: list<Shipment>, 1: array<int,string>}
     */
    private function partition(array $ids, Collection $shipments, int $branchId, ?DeliveryRun $run): array
    {
        $family = $this->branchFamily($branchId);
        $ok = [];
        $skipped = [];
        foreach ($ids as $id) {
            /** @var Shipment|null $s */
            $s = $shipments->get($id);
            if (! $s) {
                $skipped[$id] = 'Parcel not found.';
                continue;
            }
            if (! in_array($s->status, self::READY_STATUSES, true)) {
                $skipped[$id] = $s->tracking_number.' is '.str_replace('_', ' ', (string) $s->status).', not sorted for delivery.';
                continue;
            }
            if (! in_array($this->branchOf($s), $family, true)) {
                $skipped[$id] = $s->tracking_number.' is at '.$this->branchName($this->branchOf($s)).', not this branch.';
                continue;
            }
            $active = $this->activeRunFor((int) $s->id);
            if ($active) {
                $skipped[$id] = $active->id === $run?->id
                    ? $s->tracking_number.' is already on this run.'
                    : $s->tracking_number.' is already on '.$active->run_number.'.';
                continue;
            }
            $withRider = DeliveryAssignment::query()->where('shipment_id', $s->id)
                ->whereIn('status', ['assigned', 'accepted', 'out_for_delivery'])->first();
            if ($withRider) {
                $skipped[$id] = $s->tracking_number.' is already assigned to a rider ('.$withRider->status.').';
                continue;
            }
            $ok[] = $s;
        }

        return [$ok, $skipped];
    }

    /** @param list<Shipment> $shipments */
    private function attach(DeliveryRun $run, array $shipments, User $actor): void
    {
        $seq = (int) DeliveryRunItem::query()->where('delivery_run_id', $run->id)
            ->where('status', '!=', DeliveryRunItem::STATUS_REMOVED)->max('sequence');

        foreach ($shipments as $shipment) {
            if ($shipment->status === CourierStatus::DELIVERY_FAILED) {
                // Earlier failed attempt: back into the delivery queue first.
                $this->requeue($shipment, $run, null, $actor);
                $shipment->refresh();
            }
            $delivery = $this->workflow->createPendingForShipment($shipment, $actor->id);

            DeliveryRunItem::create([
                'delivery_run_id' => $run->id,
                'shipment_id' => $shipment->id,
                'delivery_id' => $delivery->id,
                'sequence' => ++$seq,
                'status' => DeliveryRunItem::STATUS_QUEUED,
                'attempt' => (int) ($delivery->attempt_no ?: 1),
                'cod_amount' => $this->collectable($shipment),
                'added_by' => $actor->id,
            ]);
            $this->trackInternal($shipment->id, "Added to delivery run {$run->run_number}.", $actor->id);
        }
    }

    private function deliveryForHandover(Shipment $shipment, User $actor): DeliveryAssignment
    {
        $delivery = DeliveryAssignment::query()->where('shipment_id', $shipment->id)
            ->whereNotIn('status', ['failed', 'cancelled', 'delivered'])
            ->latest('id')->first();

        return $delivery ?? $this->workflow->createPendingForShipment($shipment, $actor->id);
    }

    /** Copy the delivery's current state onto the run item. */
    private function applyDeliveryState(DeliveryRunItem $item): void
    {
        if (! $item->delivery_id || $item->status === DeliveryRunItem::STATUS_QUEUED || $item->status === DeliveryRunItem::STATUS_BAGGED || $item->status === DeliveryRunItem::STATUS_REMOVED) {
            return;
        }
        $d = DeliveryAssignment::query()->find($item->delivery_id);
        if (! $d) {
            return;
        }

        $fill = ['arrived_at' => $d->arrived_at];
        if ($d->status === 'delivered') {
            $pod = PodRecord::query()->where('shipment_id', $item->shipment_id)->latest('id')->first();
            $method = $pod?->payment_method ? strtolower((string) $pod->payment_method) : null;
            $collected = (float) ($pod?->collected_amount ?? $d->pod_collected_amount ?? 0);
            if ((float) $item->cod_amount <= 0 && $collected <= 0) {
                $method = 'prepaid';
            }
            $fill += [
                'status' => DeliveryRunItem::STATUS_DELIVERED,
                'outcome' => 'delivered',
                'delivered_at' => $d->delivered_at ?? now(),
                'collected_amount' => $method === 'prepaid' ? 0 : $collected,
                'payment_method' => $method === 'qr' ? 'online' : ($method ?: 'cash'),
            ];
        } elseif ($d->status === 'failed') {
            $fill += [
                'status' => DeliveryRunItem::STATUS_FAILED,
                'outcome' => in_array($item->outcome, ['reattempt', 'returned'], true) ? $item->outcome : 'failed',
                'failed_at' => $d->failed_at ?? now(),
                'reason' => $d->failure_reason ?: $d->remarks ?: $item->reason,
            ];
        } elseif (in_array($d->status, ['assigned', 'accepted', 'out_for_delivery'], true)) {
            $fill['status'] = DeliveryRunItem::STATUS_OUT;
        }
        $item->forceFill($fill)->save();
    }

    private function recount(DeliveryRun $run): void
    {
        $items = DeliveryRunItem::query()->where('delivery_run_id', $run->id)
            ->where('status', '!=', DeliveryRunItem::STATUS_REMOVED)->get();

        $run->forceFill([
            'expected_count' => $items->count(),
            'delivered_count' => $items->where('status', DeliveryRunItem::STATUS_DELIVERED)->count(),
            'failed_count' => $items->where('status', DeliveryRunItem::STATUS_FAILED)->count(),
            'returned_count' => $items->where('outcome', 'returned')->count(),
            'reattempt_count' => $items->where('outcome', 'reattempt')->count(),
            'cod_expected' => round((float) $items->sum('cod_amount'), 2),
            'cod_collected' => round((float) $items->where('status', DeliveryRunItem::STATUS_DELIVERED)->where('payment_method', 'cash')->sum('collected_amount'), 2),
            'online_collected' => round((float) $items->where('status', DeliveryRunItem::STATUS_DELIVERED)->where('payment_method', 'online')->sum('collected_amount'), 2),
        ])->save();
    }

    private function maybeComplete(DeliveryRun $run): void
    {
        $run->refresh();
        if ($run->status !== DeliveryRun::STATUS_OUT) {
            return;
        }
        $open = DeliveryRunItem::query()->where('delivery_run_id', $run->id)
            ->whereNotIn('status', DeliveryRunItem::TERMINAL_STATUSES)->exists();
        if (! $open && $run->expected_count > 0) {
            $run->update(['status' => DeliveryRun::STATUS_COMPLETED, 'completed_at' => now()]);
            $this->notifyBranch($run, "{$run->run_number} finished on the road",
                sprintf('%s: %d delivered, %d failed. Close the run to check cash (Rs %s) and requeue failed parcels.',
                    $run->run_number, $run->delivered_count, $run->failed_count, number_format((float) $run->cod_collected, 2)));
        }
    }

    /** Failed parcel back to the branch delivery queue with a fresh pending delivery. */
    private function requeue(Shipment $shipment, DeliveryRun $run, ?DeliveryRunItem $item, User $actor): void
    {
        $lastAttempt = (int) DeliveryAssignment::query()->where('shipment_id', $shipment->id)->max('attempt_no');
        $updates = [
            'status' => CourierStatus::SORTED_FOR_DELIVERY,
            'merchant_status' => CourierStatus::merchantStatus(CourierStatus::SORTED_FOR_DELIVERY),
            'current_branch_id' => $shipment->current_branch_id ?: $shipment->destination_branch_id ?: $run->branch_id,
        ];
        if ($item && Schema::hasColumn('shipments', 'failed_attempts')) {
            $updates['failed_attempts'] = (int) ($shipment->failed_attempts ?? 0) + 1;
        }
        $shipment->update($updates);

        $hasOpen = DeliveryAssignment::query()->where('shipment_id', $shipment->id)
            ->whereNotIn('status', ['failed', 'cancelled', 'delivered'])->exists();
        if (! $hasOpen) {
            DeliveryAssignment::create([
                'shipment_id' => $shipment->id,
                'branch_id' => $shipment->destination_branch_id ?? $shipment->current_branch_id,
                'sub_branch_id' => $shipment->destination_sub_branch_id ?? $shipment->current_sub_branch_id,
                'delivery_type' => 'last_mile',
                'status' => 'pending',
                'attempt_no' => max(1, $lastAttempt) + 1,
                'assigned_by' => $actor->id,
            ]);
        }

        $this->tracking->record(
            $shipment->fresh(),
            CourierStatus::SORTED_FOR_DELIVERY,
            $item
                ? "Back at branch after a failed attempt on {$run->run_number}; queued for re-delivery (attempt ".(max(1, $lastAttempt) + 1).').'
                : 'Queued again for re-delivery.',
            $actor->id
        );
    }

    private function initiateReturn(Shipment $shipment, DeliveryRun $run, DeliveryRunItem $item, User $actor): void
    {
        $shipment->update([
            'status' => CourierStatus::RETURN_INITIATED,
            'merchant_status' => CourierStatus::merchantStatus(CourierStatus::RETURN_INITIATED),
            'current_branch_id' => $shipment->current_branch_id ?: $shipment->destination_branch_id ?: $run->branch_id,
        ]);
        $this->tracking->record(
            $shipment->fresh(),
            CourierStatus::RETURN_INITIATED,
            'Return to merchant started after failed delivery on '.$run->run_number.($item->reason ? ': '.$item->reason : '.'),
            $actor->id
        );
        try {
            app(\Modules\Webhook\Services\WebhookService::class)->queueShipmentEvent($shipment->fresh(), 'delivery.return_initiated');
        } catch (\Throwable $e) {
            Log::info('delivery_run.return_webhook_skipped', ['shipment_id' => $shipment->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Deposit the run's collected cash POD into the branch pool (same effect as
     * POD > Deposit): one pod_deposits row, records marked deposited, shipments
     * ready for merchant settlement.
     */
    private function depositCash(DeliveryRun $run, Collection $items, User $actor): ?int
    {
        $shipmentIds = DeliveryRunItem::query()->where('delivery_run_id', $run->id)
            ->where('status', DeliveryRunItem::STATUS_DELIVERED)->where('payment_method', 'cash')
            ->pluck('shipment_id')->all();
        if ($shipmentIds === []) {
            return null;
        }

        $records = PodRecord::query()->whereIn('shipment_id', $shipmentIds)
            ->where('status', 'collected')
            ->where(fn ($q) => $q->whereNull('payment_destination')->orWhere('payment_destination', '!=', 'merchant'))
            ->lockForUpdate()->get();
        if ($records->isEmpty()) {
            return null;
        }

        $remarks = "Cash handover for {$run->run_number}".($run->rider_user_id ? ' from '.$this->userName((int) $run->rider_user_id) : '');
        $deposit = PodDeposit::create([
            'branch_id' => $run->branch_id,
            'staff_id' => $run->rider_user_id ?: $actor->id,
            'amount' => round((float) $records->sum('collected_amount'), 2),
            'status' => 'confirmed',
            'remarks' => $remarks,
        ]);

        PodRecord::query()->whereIn('id', $records->pluck('id'))->update([
            'status' => 'deposited',
            'deposited_by' => $actor->id,
            'deposited_to_branch_id' => $run->branch_id,
            'deposited_at' => now(),
            'remarks' => $remarks,
        ]);

        $ids = $records->pluck('shipment_id')->filter()->unique()->values();
        Shipment::query()->whereIn('id', $ids)->update(['pod_status' => 'deposited']);
        Shipment::query()->whereIn('id', $ids)->whereNotIn('settlement_status', ['processing', 'settled'])->update(['settlement_status' => 'ready']);

        foreach (Shipment::query()->whereIn('id', $ids)->get() as $shipment) {
            try {
                app(SettlementWorkflowService::class)->autoEnsureForShipment($shipment, 'after_deposit');
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return (int) $deposit->id;
    }

    /**
     * Area for labels and auto-order only. Uses receiver_area / receiver_city,
     * else derives it from the address. Never written back to the shipment.
     */
    public function displayArea(?Shipment $s): ?string
    {
        if (! $s) {
            return null;
        }
        $area = trim((string) ($s->receiver_area ?: $s->receiver_city ?: ''));

        return $area !== '' ? $area : $this->areaFromAddress((string) $s->delivery_address);
    }

    public function areaFromAddress(string $address): ?string
    {
        $parts = array_values(array_filter(
            array_map(fn ($p) => trim((string) preg_replace('/\s+/', ' ', $p)), explode(',', $address)),
            fn ($p) => $p !== ''
        ));
        if ($parts === []) {
            return null;
        }
        $known = $this->knownAreas();
        foreach ($parts as $p) {
            if (isset($known[Str::lower($p)])) {
                return $known[Str::lower($p)];
            }
        }
        foreach ($parts as $p) {
            foreach ($known as $key => $label) {
                if (preg_match('/(^|[\s\-])'.preg_quote($key, '/').'($|[\s\-])/i', $p)) {
                    return $label;
                }
            }
        }
        // Token just before a ward token like "Kathmandu-31".
        $ward = null;
        foreach ($parts as $i => $p) {
            if (preg_match('/^[\pL .]+-\s*\d{1,2}$/u', $p)) {
                $ward = $i;
                break;
            }
        }
        $candidates = $ward !== null ? array_reverse(array_slice($parts, 0, $ward)) : $parts;
        foreach ($candidates as $p) {
            if ($this->looksLikeLocality($p)) {
                return Str::title($p);
            }
        }
        if ($ward !== null) {
            $name = trim((string) preg_replace('/-\s*\d{1,2}$/', '', $parts[$ward]));

            return $name !== '' ? Str::title($name) : null;
        }

        return null;
    }

    private function looksLikeLocality(string $p): bool
    {
        if (mb_strlen($p) < 3 || mb_strlen($p) > 40 || preg_match('/\d/', $p)) {
            return false;
        }

        return ! preg_match('/\b(marg|marga|margh|galli|gali|road|rd|street|st|sadak|path|lane|nepal|province|pradesh|metropolitan|municipality|nagarpalika|district|zone|cafe|school|college|hospital|hotel|restaurant|bank|secondary|temple|mandir|office|building|complex|apartment|near|opposite|behind|front|house|tower)\b/i', $p);
    }

    /** @return array<string, string> lower-case name => label */
    private function knownAreas(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $names = ['Baneshwar', 'Baneshwor', 'Koteshwor', 'Koteshwar', 'Sinamangal', 'Tinkune', 'Gaushala', 'Chabahil', 'Boudha', 'Jorpati',
            'Baluwatar', 'Maharajgunj', 'Lazimpat', 'Thamel', 'Kalanki', 'Balaju', 'Gongabu', 'Samakhusi', 'Basundhara', 'Budhanilkantha',
            'Tokha', 'Kalimati', 'Teku', 'Tripureshwor', 'Thapathali', 'Putalisadak', 'Dillibazar', 'Bagbazar', 'Kamalpokhari', 'Naxal',
            'Hattisar', 'Battisputali', 'Pepsicola', 'Thimi', 'Lokanthali', 'Kausaltar', 'Sanepa', 'Jawalakhel', 'Pulchowk', 'Kupondole',
            'Lagankhel', 'Satdobato', 'Imadol', 'Gwarko', 'Kumaripati', 'Jhamsikhel', 'Bhaisepati', 'Chapagaun', 'Kirtipur', 'Balkhu',
            'Kuleshwor', 'Swayambhu', 'Sitapaila', 'Thankot', 'Sukedhara', 'Dhumbarahi', 'Chuchepati', 'Mandikatar', 'Kapan', 'Gokarna',
            'Mulpani', 'Sankhamul', 'Shantinagar', 'Anamnagar', 'Maitighar', 'Babarmahal', 'Suryabinayak', 'Nakhipot', 'Ekantakuna',
            'Dhapakhel', 'Harisiddhi', 'Godawari', 'Chakupat', 'Mangal Bazar', 'Sundhara', 'New Road', 'Newroad', 'Ason', 'Indrachowk',
            'Bhotahiti', 'Jamal', 'Kantipath', 'Durbarmarg', 'Ratnapark', 'Bansbari', 'Golfutar', 'Dhapasi', 'Manamaiju', 'Nayabazar',
            'Sorakhutte', 'Lainchaur', 'Kalopul', 'Bijulibazar', 'Minbhawan', 'Mid Baneshwor', 'Old Baneshwor', 'Buddhanagar', 'Bhimsengola',
            'Mahalaxmisthan', 'Sanothimi', 'Radhe Radhe', 'Suryabinayak', 'Tikathali', 'Sallaghari', 'Jadibuti', 'Narephat', 'Bafal', 'Soltimode'];
        $cache = [];
        foreach ($names as $n) {
            $cache[Str::lower($n)] = $n;
        }
        try {
            if (Schema::hasTable('coverage_locations') && Schema::hasColumn('coverage_locations', 'area')) {
                foreach (DB::table('coverage_locations')->whereNotNull('area')->distinct()->pluck('area') as $a) {
                    $a = trim((string) $a);
                    if ($a !== '' && mb_strlen($a) <= 40 && ! preg_match('/\b(kathmandu|lalitpur|bhaktapur|metropolitan|municipality)\b/i', $a)) {
                        $cache[Str::lower($a)] ??= $a;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Built-in list only.
        }

        return $cache;
    }

    private function autoSequence(DeliveryRun $run): void
    {
        $items = DeliveryRunItem::query()->where('delivery_run_id', $run->id)
            ->where('status', '!=', DeliveryRunItem::STATUS_REMOVED)
            ->with('shipment:id,receiver_area,receiver_city,delivery_address,delivery_lat,delivery_lng')
            ->get();

        $sorted = $items->sortBy(fn (DeliveryRunItem $i) => [
            Str::lower($this->displayArea($i->shipment) ?? 'zzz'),
            Str::lower(trim((string) ($i->shipment?->receiver_city ?? ''))),
            Str::lower(trim((string) ($i->shipment?->delivery_address ?? ''))),
            $i->id,
        ])->values();

        foreach ($sorted as $idx => $item) {
            if ((int) $item->sequence !== $idx + 1) {
                $item->update(['sequence' => $idx + 1]);
            }
        }
    }

    private function resequence(DeliveryRun $run): void
    {
        $items = DeliveryRunItem::query()->where('delivery_run_id', $run->id)
            ->where('status', '!=', DeliveryRunItem::STATUS_REMOVED)->orderBy('sequence')->orderBy('id')->get();
        foreach ($items as $idx => $item) {
            if ((int) $item->sequence !== $idx + 1) {
                $item->update(['sequence' => $idx + 1]);
            }
        }
    }

    private function shipmentRow(Shipment $s): array
    {
        return [
            'shipment_id' => $s->id,
            'tracking_number' => $s->tracking_number,
            'shipment_status' => $s->status,
            'merchant' => $s->relationLoaded('merchant') && $s->merchant ? ['id' => $s->merchant->id, 'name' => $s->merchant->name] : null,
            'receiver_name' => $s->receiver_name,
            'receiver_phone' => $s->receiver_phone,
            'delivery_address' => $s->delivery_address,
            'receiver_area' => $s->receiver_area,
            'receiver_city' => $s->receiver_city,
            'area' => $this->displayArea($s),
            'area_derived' => trim((string) ($s->receiver_area ?: $s->receiver_city ?: '')) === '' && $this->displayArea($s) !== null,
            'delivery_lat' => $s->delivery_lat,
            'delivery_lng' => $s->delivery_lng,
            'payment_type' => $s->payment_type,
            'cod_amount' => $this->collectable($s),
            'weight' => (float) ($s->chargeable_weight ?: $s->weight ?: 0),
            'special_instructions' => $s->special_instructions,
            'branch_id' => $this->branchOf($s),
        ];
    }

    private function collectable(Shipment $s): float
    {
        return (float) $s->collect_amount;
    }

    /** COD still to collect on a run: live collect amount for open stops, snapshot for finished ones. */
    private function liveCodExpected(DeliveryRun $run): float
    {
        $items = DeliveryRunItem::query()->where('delivery_run_id', $run->id)
            ->where('status', '!=', DeliveryRunItem::STATUS_REMOVED)->with('shipment')->get();

        return round((float) $items->sum(fn (DeliveryRunItem $i) => $this->itemCod($i)), 2);
    }

    private function itemCod(DeliveryRunItem $item): float
    {
        if (in_array($item->status, [DeliveryRunItem::STATUS_DELIVERED, DeliveryRunItem::STATUS_FAILED], true) || ! $item->shipment) {
            return (float) $item->cod_amount;
        }

        return $this->collectable($item->shipment);
    }

    private function branchOf(?Shipment $s): int
    {
        if (! $s) {
            return 0;
        }

        return (int) ($s->current_sub_branch_id ?: $s->current_branch_id ?: $s->destination_sub_branch_id ?: $s->destination_branch_id ?: 0);
    }

    private function whereAtBranch(Builder $q, int $branchId): Builder
    {
        $family = $this->branchFamily($branchId);

        return $q->where(function (Builder $w) use ($family) {
            $w->whereIn('current_sub_branch_id', $family)
                ->orWhere(fn (Builder $x) => $x->whereNull('current_sub_branch_id')->whereIn('current_branch_id', $family))
                ->orWhere(fn (Builder $x) => $x->whereNull('current_branch_id')->whereNull('current_sub_branch_id')
                    ->where(fn (Builder $y) => $y->whereIn('destination_sub_branch_id', $family)->orWhereIn('destination_branch_id', $family)));
        });
    }

    /**
     * The branch and its sub-branches: a branch manager runs deliveries for both.
     *
     * @return list<int>
     */
    public function branchFamily(int $branchId): array
    {
        if ($branchId <= 0) {
            return [];
        }
        static $cache = [];
        if (! isset($cache[$branchId])) {
            $children = Schema::hasColumn('branches', 'parent_id')
                ? DB::table('branches')->where('parent_id', $branchId)->pluck('id')->map(fn ($v) => (int) $v)->all()
                : [];
            $cache[$branchId] = array_values(array_unique(array_merge([$branchId], $children)));
        }

        return $cache[$branchId];
    }

    private function lockRun(DeliveryRun $run): DeliveryRun
    {
        return DeliveryRun::query()->lockForUpdate()->findOrFail($run->id);
    }

    private function assertEditable(DeliveryRun $run, ?string $message = null): void
    {
        if (! $run->isEditable()) {
            throw ValidationException::withMessages(['status' => [$message ?? ('Parcels can only be changed before the run starts. '.$this->statusMessage($run))]]);
        }
    }

    private function statusMessage(DeliveryRun $run): string
    {
        return match ($run->status) {
            DeliveryRun::STATUS_OPEN => "{$run->run_number} has no rider yet.",
            DeliveryRun::STATUS_ASSIGNED => "{$run->run_number} is assigned; the rider is collecting and bagging parcels.",
            DeliveryRun::STATUS_OUT => "{$run->run_number} is out for delivery.",
            DeliveryRun::STATUS_COMPLETED => "{$run->run_number} is back and waiting to be closed.",
            DeliveryRun::STATUS_CLOSED => "{$run->run_number} is already closed.",
            DeliveryRun::STATUS_CANCELLED => "{$run->run_number} was cancelled.",
            default => "{$run->run_number} is {$run->status}.",
        };
    }

    private function validateVehicle(?string $type): void
    {
        if ($type !== null && $type !== '' && ! in_array($type, DeliveryRun::VEHICLE_TYPES, true)) {
            throw ValidationException::withMessages(['vehicle_type' => ['Vehicle type must be one of: '.implode(', ', DeliveryRun::VEHICLE_TYPES).'.']]);
        }
    }

    /** @return list<int> */
    private function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn ($v) => $v > 0)));
    }

    private function reasonText(array $skipped): string
    {
        $reasons = array_values($skipped);
        $text = implode(' ', array_slice($reasons, 0, 3));

        return count($reasons) > 3 ? $text.' (+'.(count($reasons) - 3).' more)' : $text;
    }

    private function trackInternal(int $shipmentId, string $description, ?int $actorId): void
    {
        try {
            $shipment = Shipment::query()->find($shipmentId);
            if ($shipment) {
                $this->tracking->record($shipment, (string) $shipment->status, $description, $actorId, 'internal');
            }
        } catch (\Throwable $e) {
            Log::info('delivery_run.track_skipped', ['shipment_id' => $shipmentId, 'error' => $e->getMessage()]);
        }
    }

    private function notifyRider(DeliveryRun $run, string $event, array $ctx = []): void
    {
        if (! $run->rider_user_id) {
            return;
        }
        $count = (int) $run->expected_count;
        $cod = number_format((float) $run->cod_expected, 2);
        [$title, $message] = match ($event) {
            'dispatched' => ["{$run->run_number} is out for delivery", "{$count} parcel".($count === 1 ? '' : 's')." on {$run->run_number}. Cash POD to collect: Rs {$cod}."],
            'cancelled' => ["{$run->run_number} cancelled", "{$run->run_number} was cancelled by the branch."],
            'added' => [
                ($ctx['count'] ?? 1).' new parcel'.(($ctx['count'] ?? 1) === 1 ? '' : 's')." added to {$run->run_number}",
                ($ctx['count'] ?? 1).' new parcel'.(($ctx['count'] ?? 1) === 1 ? '' : 's')." added to {$run->run_number} - collect and bag them before you start. Now {$count} parcels, COD Rs {$cod}.",
            ],
            default => ["New delivery run {$run->run_number}", "{$run->run_number}: {$count} parcel".($count === 1 ? '' : 's')." (COD Rs {$cod}) - collect from the warehouse and bag them, then start the run."],
        };
        $data = ['delivery_run_id' => $run->id, 'run_number' => $run->run_number, 'rider_user_id' => (int) $run->rider_user_id, 'event' => $event];

        // Rider-only notice: not copied to the branch feed.
        // Personal inbox when the database notifications table exists.
        try {
            if (Schema::hasTable('notifications')) {
                DB::table('notifications')->insert([
                    'id' => (string) Str::uuid(),
                    'type' => 'delivery_run.'.$event,
                    'notifiable_type' => User::class,
                    'notifiable_id' => (int) $run->rider_user_id,
                    'data' => json_encode(['type' => 'delivery_run.'.$event, 'title' => $title, 'message' => $message] + $data),
                    'read_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::info('delivery_run.notify_user_skipped', ['run_id' => $run->id, 'error' => $e->getMessage()]);
        }
    }

    private function notifyBranch(DeliveryRun $run, string $title, string $message, array $data = []): void
    {
        try {
            if (! Schema::hasTable('staff_notifications')) {
                return;
            }
            $cols = Schema::getColumnListing('staff_notifications');
            DB::table('staff_notifications')->insert(array_intersect_key([
                'title' => $title,
                'message' => $message,
                'branch_id' => $run->branch_id,
                'shipment_id' => null,
                'type' => 'delivery_run',
                'is_read' => false,
                'data_json' => json_encode($data + ['delivery_run_id' => $run->id, 'run_number' => $run->run_number]),
                'created_at' => now(),
                'updated_at' => now(),
            ], array_flip($cols)));
        } catch (\Throwable $e) {
            Log::info('delivery_run.notify_skipped', ['run_id' => $run->id, 'error' => $e->getMessage()]);
        }
    }

    private function branchName(?int $branchId): string
    {
        if (! $branchId) {
            return 'no branch';
        }
        static $cache = [];

        return $cache[$branchId] ??= (string) (Branch::query()->whereKey($branchId)->value('name') ?? "Branch #{$branchId}");
    }

    private function userName(int $id): ?string
    {
        static $cache = [];

        return $cache[$id] ??= (string) (DB::table('users')->where('id', $id)->value('name') ?? '');
    }
}
