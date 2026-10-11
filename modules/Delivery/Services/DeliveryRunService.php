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
    public const RIDER_ROLES = ['rider', 'delivery_staff', 'pickup_rider', 'staff', 'sub_branch_manager', 'branch_manager'];

    public function __construct(
        private readonly DeliveryWorkflowService $workflow,
        private readonly TrackingService $tracking,
    ) {
    }

    /* ------------------------------------------------------------------ */
    /* Numbering                                                           */
    /* ------------------------------------------------------------------ */

    /** DR-{BRANCH}-{seq}, same branch tag and padding as TR numbers. */
    public function nextRunNumber(int $branchId): string
    {
        $prefix = 'DR-'.app(TransferContainerService::class)->branchTag($branchId).'-';

        $last = DB::table('delivery_runs')
            ->where('run_number', 'like', $prefix.'%')
            ->orderByRaw('LENGTH(run_number) DESC')
            ->orderByDesc('run_number')
            ->lockForUpdate()
            ->value('run_number');
        $number = ((is_string($last) && preg_match('/(\d+)$/', $last, $m)) ? (int) $m[1] : 0) + 1;

        do {
            $candidate = $prefix.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
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

    /** @return array{run: DeliveryRun, added: list<int>, skipped: array<int,string>} */
    public function addItems(DeliveryRun $run, array $shipmentIds, User $actor): array
    {
        $shipmentIds = $this->ids($shipmentIds);
        if ($shipmentIds === []) {
            throw ValidationException::withMessages(['shipment_ids' => ['Select at least one parcel to add.']]);
        }

        return DB::transaction(function () use ($run, $shipmentIds, $actor) {
            $run = $this->lockRun($run);
            $this->assertEditable($run);

            $shipments = Shipment::query()->whereIn('id', $shipmentIds)->lockForUpdate()->get()->keyBy('id');
            [$ok, $skipped] = $this->partition($shipmentIds, $shipments, (int) $run->branch_id, $run);
            if ($ok !== []) {
                $this->attach($run, $ok, $actor);
                $this->recount($run);
            }

            return ['run' => $run->fresh(), 'added' => array_map(fn (Shipment $s) => (int) $s->id, $ok), 'skipped' => $skipped];
        });
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
                $item->update([
                    'status' => DeliveryRunItem::STATUS_REMOVED,
                    'outcome' => 'removed',
                    'reason' => $reason ?: 'Removed before dispatch.',
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
            $this->assertEditable($run, 'The rider can only be changed before the run is dispatched.');
            $rider = $this->validateRider($riderId, (int) $run->branch_id);

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
     * Hand the run to its rider: every parcel's delivery is assigned to the
     * rider, accepted and marked out for delivery (same tracking, webhooks and
     * store callbacks as the single-delivery flow). All or nothing.
     */
    public function dispatch(DeliveryRun $run, User $actor, array $meta = []): DeliveryRun
    {
        $this->validateVehicle($meta['vehicle_type'] ?? null);

        $run = DB::transaction(function () use ($run, $actor, $meta) {
            $run = $this->lockRun($run);

            if (! empty($meta['rider_user_id']) && $run->isEditable()) {
                $rider = $this->validateRider((int) $meta['rider_user_id'], (int) $run->branch_id);
                $run->update(['rider_user_id' => $rider->id, 'assigned_by' => $actor->id, 'assigned_at' => now(), 'status' => DeliveryRun::STATUS_ASSIGNED]);
            }
            foreach (['vehicle_type', 'vehicle_number'] as $key) {
                if (! empty($meta[$key])) {
                    $run->{$key} = $meta[$key];
                }
            }
            $run->save();

            if ($run->status === DeliveryRun::STATUS_OPEN) {
                throw ValidationException::withMessages(['rider_user_id' => ['Assign a rider before dispatching the run.']]);
            }
            if ($run->status !== DeliveryRun::STATUS_ASSIGNED) {
                throw ValidationException::withMessages(['status' => [$this->statusMessage($run)]]);
            }

            $rider = $this->validateRider((int) $run->rider_user_id, (int) $run->branch_id);
            $items = DeliveryRunItem::query()->where('delivery_run_id', $run->id)
                ->where('status', DeliveryRunItem::STATUS_QUEUED)->orderBy('sequence')->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => ['The run has no parcels. Add parcels or cancel it.']]);
            }

            foreach ($items as $item) {
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
                    if ($delivery->status === 'accepted') {
                        $this->workflow->outForDelivery($delivery, $rider);
                    }
                } catch (ValidationException $e) {
                    $first = collect($e->errors())->flatten()->first();
                    throw ValidationException::withMessages(['items' => [
                        $shipment->tracking_number.': '.($first ?: 'cannot be sent out.'),
                    ]]);
                }

                $item->update([
                    'delivery_id' => $delivery->id,
                    'attempt' => (int) ($delivery->attempt_no ?: $item->attempt ?: 1),
                    'status' => DeliveryRunItem::STATUS_OUT,
                    'cod_amount' => $this->collectable($shipment),
                ]);
                $this->trackInternal($shipment->id, "Out on {$run->run_number} with {$rider->name}.", $actor->id);
            }

            $run->update([
                'status' => DeliveryRun::STATUS_OUT,
                'dispatched_at' => now(),
                'dispatched_by' => $actor->id,
            ]);
            $this->recount($run);

            return $run->fresh();
        });

        $this->notifyRider($run, 'dispatched');

        return $run;
    }

    /** Rider starts an assigned run from the rider app (after collecting the parcels). */
    public function startByRider(DeliveryRun $run, User $rider): DeliveryRun
    {
        if ((int) $run->rider_user_id !== (int) $rider->id) {
            abort(403, 'This run is assigned to another rider.');
        }

        return $this->dispatch($run, $rider);
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
            $this->assertEditable($run, 'Only a run that has not been dispatched can be cancelled.');

            $items = DeliveryRunItem::query()->where('delivery_run_id', $run->id)
                ->where('status', '!=', DeliveryRunItem::STATUS_REMOVED)->get();
            foreach ($items as $item) {
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
        $run->loadMissing(['branch:id,name,code,phone,address', 'rider:id,name,phone']);
        $out = [
            'id' => $run->id,
            'run_number' => $run->run_number,
            'display_number' => $run->run_number,
            'status' => $run->status,
            'branch' => $run->branch ? ['id' => $run->branch->id, 'name' => $run->branch->name, 'phone' => $run->branch->phone, 'address' => $run->branch->address] : null,
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
            'cod_expected' => (float) $run->cod_expected,
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
            'cod_amount' => (float) $item->cod_amount,
            'collected_amount' => (float) $item->collected_amount,
            'payment_method' => $item->payment_method,
            'delivered_at' => $item->delivered_at?->toIso8601String(),
            'failed_at' => $item->failed_at?->toIso8601String(),
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
        if (preg_match('/^(?:DR-?)?0*(\d{1,9})$/', $s, $m)) {
            $suffix = '-'.str_pad($m[1], 6, '0', STR_PAD_LEFT);

            return $q->where('run_number', 'like', 'DR-%'.$suffix)->latest('id')->first();
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
        if (! $item->delivery_id || $item->status === DeliveryRunItem::STATUS_QUEUED || $item->status === DeliveryRunItem::STATUS_REMOVED) {
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

    private function autoSequence(DeliveryRun $run): void
    {
        $items = DeliveryRunItem::query()->where('delivery_run_id', $run->id)
            ->where('status', '!=', DeliveryRunItem::STATUS_REMOVED)
            ->with('shipment:id,receiver_area,receiver_city,delivery_address,delivery_lat,delivery_lng')
            ->get();

        $sorted = $items->sortBy(fn (DeliveryRunItem $i) => [
            Str::lower(trim((string) ($i->shipment?->receiver_area ?: $i->shipment?->receiver_city ?: 'zzz'))),
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
            'area' => trim((string) ($s->receiver_area ?: $s->receiver_city ?: '')) ?: null,
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
        $type = strtolower((string) $s->payment_type);
        if (! in_array($type, ['pod', 'cod', 'to_pay'], true)) {
            return 0.0;
        }

        return round((float) ($s->total_collectable_amount ?: $s->total_collectable ?: $s->pod_amount ?: 0), 2);
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
            throw ValidationException::withMessages(['status' => [$message ?? ('Parcels can only be changed before dispatch. '.$this->statusMessage($run))]]);
        }
    }

    private function statusMessage(DeliveryRun $run): string
    {
        return match ($run->status) {
            DeliveryRun::STATUS_OPEN => "{$run->run_number} has no rider yet.",
            DeliveryRun::STATUS_ASSIGNED => "{$run->run_number} is assigned and not yet dispatched.",
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

    private function notifyRider(DeliveryRun $run, string $event): void
    {
        if (! $run->rider_user_id) {
            return;
        }
        $count = (int) $run->expected_count;
        $cod = number_format((float) $run->cod_expected, 2);
        [$title, $message] = match ($event) {
            'dispatched' => ["{$run->run_number} is out for delivery", "{$count} parcel".($count === 1 ? '' : 's')." on {$run->run_number}. Cash POD to collect: Rs {$cod}. Open Deliveries to work the run."],
            'cancelled' => ["{$run->run_number} cancelled", "{$run->run_number} was cancelled by the branch."],
            default => ["New delivery run {$run->run_number}", "{$count} parcel".($count === 1 ? '' : 's')." assigned to you on {$run->run_number} (COD Rs {$cod}). Collect them at the branch."],
        };
        $data = ['delivery_run_id' => $run->id, 'run_number' => $run->run_number, 'rider_user_id' => (int) $run->rider_user_id, 'event' => $event];

        $this->notifyBranch($run, $title, $message, $data);

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
