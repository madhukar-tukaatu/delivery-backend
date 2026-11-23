<?php

namespace Modules\Pickup\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Branch\Models\Branch;
use Modules\Pickup\Models\PickupRequest;
use Modules\Pickup\Models\PickupRun;
use Modules\Pickup\Models\PickupRunItem;
use Modules\Pickup\Support\PickupStatus;
use App\Support\CourierStatus;

class PickupRunService
{
    /* ------------------------------------------------------------------ */
    /* Query / Read                                                       */
    /* ------------------------------------------------------------------ */

    public function findByNumber(string $runNumber, int $scope = 0): ?PickupRun
    {
        $s = trim($runNumber);
        if ($s === '') {
            return null;
        }

        $q = PickupRun::query();
        if ($scope > 0) {
            $q->whereIn('branch_id', $this->branchFamily($scope));
        }

        // Exact match (case insensitive)
        $exact = (clone $q)->whereRaw('LOWER(run_number) = ?', [strtolower($s)])->first();
        if ($exact) {
            return $exact;
        }

        // Support formats like "RPR-01", "rpr-01", "RPR-1", "PR-RUN-001", "001", "1", "01"
        if (preg_match('/^(?:(?:RPR|PR-RUN|RN-PR|PR)-?)?0*(\d{1,9})$/i', $s, $m)) {
            $num = (int) $m[1];
            $candidates = [
                sprintf('RPR-%02d', $num),
                sprintf('RPR-%03d', $num),
                sprintf('RPR-%d', $num),
                sprintf('PR-RUN-%03d', $num),
                sprintf('PR-RUN-%06d', $num),
            ];
            $found = (clone $q)->whereIn('run_number', $candidates)->first();
            if ($found) {
                return $found;
            }
            $wild = (clone $q)->where('run_number', 'like', '%-'.str_pad((string) $num, 2, '0', STR_PAD_LEFT))
                ->orWhere('run_number', 'like', '%-'.str_pad((string) $num, 3, '0', STR_PAD_LEFT))
                ->latest('id')->first();
            if ($wild) {
                return $wild;
            }
        }

        return null;
    }

    public function readyQuery(int $branchId): Builder
    {
        $family = $this->branchFamily($branchId);

        return PickupRequest::query()
            ->where(function ($w) use ($family) {
                $w->whereIn('pickup_sub_branch_id', $family)
                    ->orWhere(fn ($x) => $x->whereNull('pickup_sub_branch_id')->whereIn('pickup_branch_id', $family))
                    ->orWhere(fn ($x) => $x->whereNull('pickup_branch_id')->whereNull('pickup_sub_branch_id')
                        ->where(fn ($y) => $y->whereIn('sub_branch_id', $family)->orWhereIn('branch_id', $family)));
            })
            ->whereIn('status', [PickupStatus::REQUESTED, PickupStatus::ASSIGNED, 'pending', 'requested'])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('pickup_run_items as pri')
                    ->join('pickup_runs as pr', 'pr.id', '=', 'pri.pickup_run_id')
                    ->whereColumn('pri.pickup_request_id', 'pickup_requests.id')
                    ->whereIn('pr.status', ['open', 'assigned', 'in_progress', 'completed'])
                    ->where('pri.status', '!=', PickupRunItem::STATUS_REMOVED);
            });
    }

    /** @return list<array<string,mixed>> */
    public function readyList(int $branchId, ?string $search = null, int $limit = 500): array
    {
        $q = $this->readyQuery($branchId)->with('merchant');
        if ($search !== null && trim($search) !== '') {
            $term = trim($search);
            $q->where(function ($w) use ($term) {
                $w->where('request_number', 'like', "%{$term}%")
                    ->orWhere('pickup_name', 'like', "%{$term}%")
                    ->orWhere('pickup_phone', 'like', "%{$term}%")
                    ->orWhere('pickup_area', 'like', "%{$term}%")
                    ->orWhere('pickup_city', 'like', "%{$term}%")
                    ->orWhere('pickup_address', 'like', "%{$term}%");
            });
        }

        $rows = $q->orderBy('pickup_area')->orderBy('pickup_city')->orderBy('id')->limit($limit)->get();

        return $rows->map(fn (PickupRequest $pr) => $this->pickupRow($pr))->values()->all();
    }

    public const RIDER_ROLES = [
        'rider',
        'pickup_rider',
        'delivery_rider',
        'delivery_staff',
        'staff',
        'driver',
        'sub_branch_manager',
        'branch_manager',
    ];

    /** @return Collection<int,User> */
    public function riders(int $branchId): Collection
    {
        $family = $branchId > 0 ? $this->branchFamily($branchId) : [];

        $q = User::query()
            ->select(['id', 'name', 'phone', 'email', 'branch_id', 'role'])
            ->where('is_active', true)
            ->where(function ($w) {
                $w->whereIn('role', self::RIDER_ROLES)
                    ->when(Schema::hasColumn('users', 'designation'), fn ($q) => $q->orWhere('designation', 'rider'))
                    ->orWhereHas('roles', fn ($r) => $r->whereIn('name', self::RIDER_ROLES));
            });

        if ($branchId > 0 && ! empty($family)) {
            $q->where(function ($b) use ($family) {
                $b->whereIn('branch_id', $family)
                  ->orWhereNull('branch_id');
            });
        }

        $res = $q->orderBy('name')->get();

        // If specific rider query returned empty, allow all active staff/users of the branch
        if ($res->isEmpty()) {
            $fallback = User::query()->select(['id', 'name', 'phone', 'email', 'branch_id', 'role'])->where('is_active', true);
            if ($branchId > 0 && ! empty($family)) {
                $fallback->whereIn('branch_id', $family);
            }
            $res = $fallback->orderBy('name')->limit(100)->get();
        }

        return $res;
    }

    /* ------------------------------------------------------------------ */
    /* Create / Edit                                                       */
    /* ------------------------------------------------------------------ */

    public function create(array $pickupRequestIds, int $scope, array $data, User $actor): array
    {
        $pickupRequestIds = $this->ids($pickupRequestIds);
        if ($pickupRequestIds === []) {
            throw ValidationException::withMessages(['pickup_request_ids' => ['Select at least one pickup request.']]);
        }

        $branchId = (int) ($data['branch_id'] ?? $scope);
        if ($branchId <= 0) {
            $first = PickupRequest::query()->find($pickupRequestIds[0]);
            $branchId = (int) ($first?->pickup_sub_branch_id ?: $first?->pickup_branch_id ?: $first?->branch_id ?: 0);
        }
        if ($branchId <= 0) {
            throw ValidationException::withMessages(['branch_id' => ['Branch could not be determined.']]);
        }

        $this->validateVehicle($data['vehicle_type'] ?? null);

        $run = DB::transaction(function () use ($pickupRequestIds, $branchId, $data, $actor) {
            $runNumber = $this->nextRunNumber($branchId);
            $riderId = ! empty($data['rider_user_id']) ? (int) $data['rider_user_id'] : null;

            $run = PickupRun::create([
                'run_number' => $runNumber,
                'branch_id' => $branchId,
                'rider_user_id' => $riderId,
                'vehicle_type' => $data['vehicle_type'] ?? null,
                'vehicle_number' => $data['vehicle_number'] ?? null,
                'area_label' => $data['area_label'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => $riderId ? PickupRun::STATUS_ASSIGNED : PickupRun::STATUS_OPEN,
                'created_by' => $actor->id,
                'assigned_by' => $riderId ? $actor->id : null,
                'assigned_at' => $riderId ? now() : null,
            ]);

            return $run;
        });

        $addResult = $this->addItems($run, $pickupRequestIds, $actor);

        if (! empty($data['auto_sequence']) || count($addResult['added']) > 1) {
            $this->autoSequence($run);
        }

        $run = $run->fresh(['items', 'branch', 'rider']);

        return [
            'run' => $run,
            'added' => $addResult['added'],
            'skipped' => $addResult['skipped'],
        ];
    }

    public function addItems(PickupRun $run, array $pickupRequestIds, User $actor): array
    {
        $this->assertEditable($run);
        $pickupRequestIds = $this->ids($pickupRequestIds);

        $pickups = PickupRequest::query()->whereIn('id', $pickupRequestIds)->get()->keyBy('id');
        $family = $this->branchFamily((int) $run->branch_id);

        $busy = PickupRunItem::query()
            ->join('pickup_runs as pr', 'pr.id', '=', 'pickup_run_items.pickup_run_id')
            ->whereIn('pickup_run_items.pickup_request_id', $pickupRequestIds)
            ->whereIn('pr.status', ['open', 'assigned', 'in_progress', 'completed'])
            ->where('pickup_run_items.status', '!=', PickupRunItem::STATUS_REMOVED)
            ->pluck('pickup_run_items.pickup_request_id')
            ->all();
        $busyMap = array_fill_keys($busy, true);

        $added = [];
        $skipped = [];
        $nextSeq = (int) ($run->items()->max('sequence') ?: 0) + 1;

        DB::transaction(function () use ($run, $pickupRequestIds, $pickups, $family, $busyMap, $actor, &$added, &$skipped, &$nextSeq) {
            foreach ($pickupRequestIds as $pid) {
                /** @var PickupRequest|null $pr */
                $pr = $pickups->get($pid);
                if (! $pr) {
                    $skipped[$pid] = 'Pickup request not found.';
                    continue;
                }
                if (isset($busyMap[$pid])) {
                    $skipped[$pid] = "{$pr->request_number} is already on an active run.";
                    continue;
                }

                $prBranch = (int) ($pr->pickup_sub_branch_id ?: $pr->pickup_branch_id ?: $pr->sub_branch_id ?: $pr->branch_id ?: 0);
                if ($prBranch > 0 && ! in_array($prBranch, $family, true)) {
                    $skipped[$pid] = "{$pr->request_number} belongs to a different branch.";
                    continue;
                }

                PickupRunItem::create([
                    'pickup_run_id' => $run->id,
                    'pickup_request_id' => $pr->id,
                    'sequence' => $nextSeq++,
                    'status' => PickupRunItem::STATUS_QUEUED,
                    'added_by' => $actor->id,
                ]);

                $prUpdates = [];
                if (Schema::hasColumn('pickup_requests', 'pickup_run_id')) {
                    $prUpdates['pickup_run_id'] = $run->id;
                }
                if ($run->rider_user_id) {
                    $prUpdates['assigned_to'] = $run->rider_user_id;
                    $prUpdates['assigned_by'] = $actor->id;
                    $prUpdates['assigned_at'] = now();
                    if (in_array(strtolower((string) ($pr->status?->value ?? $pr->status ?? '')), ['requested', 'pending'], true)) {
                        $prUpdates['status'] = PickupStatus::ASSIGNED;
                    }
                }
                if (! empty($prUpdates)) {
                    $pr->update($prUpdates);
                }

                if ($run->rider_user_id) {
                    DB::table('pickup_request_shipments')
                        ->join('shipments', 'shipments.id', '=', 'pickup_request_shipments.shipment_id')
                        ->where('pickup_request_shipments.pickup_request_id', $pr->id)
                        ->whereNull('pickup_request_shipments.removed_at')
                        ->where('shipments.status', CourierStatus::AWAITING_PICKUP)
                        ->update([
                            'shipments.status' => CourierStatus::PICKUP_ASSIGNED,
                            'shipments.updated_at' => now(),
                        ]);
                }

                $added[] = $pid;
            }

            $this->recount($run);
        });

        return ['run' => $run->fresh(['items']), 'added' => $added, 'skipped' => $skipped];
    }

    public function removeItems(PickupRun $run, array $pickupRequestIds, ?string $reason, User $actor): array
    {
        $this->assertEditable($run);
        $pickupRequestIds = $this->ids($pickupRequestIds);

        $removed = [];
        DB::transaction(function () use ($run, $pickupRequestIds, $reason, &$removed) {
            $items = PickupRunItem::query()->where('pickup_run_id', $run->id)
                ->whereIn('pickup_request_id', $pickupRequestIds)
                ->where('status', '!=', PickupRunItem::STATUS_REMOVED)
                ->get();

            foreach ($items as $item) {
                $item->update([
                    'status' => PickupRunItem::STATUS_REMOVED,
                    'reason' => $reason,
                    'removed_at' => now(),
                ]);
                $removed[] = $item->pickup_request_id;
            }

            if (Schema::hasColumn('pickup_requests', 'pickup_run_id')) {
                PickupRequest::query()->whereIn('id', $removed)->update(['pickup_run_id' => null]);
            }

            PickupRequest::query()->whereIn('id', $removed)
                ->where('status', PickupStatus::ASSIGNED)
                ->update([
                    'assigned_to' => null,
                    'assigned_by' => null,
                    'assigned_at' => null,
                    'status' => PickupStatus::REQUESTED,
                ]);

            DB::table('pickup_request_shipments')
                ->join('shipments', 'shipments.id', '=', 'pickup_request_shipments.shipment_id')
                ->whereIn('pickup_request_shipments.pickup_request_id', $removed)
                ->whereNull('pickup_request_shipments.removed_at')
                ->where('shipments.status', CourierStatus::PICKUP_ASSIGNED)
                ->update([
                    'shipments.status' => CourierStatus::AWAITING_PICKUP,
                    'shipments.updated_at' => now(),
                ]);

            $this->resequence($run);
            $this->recount($run);
        });

        return ['run' => $run->fresh(['items']), 'removed' => $removed];
    }

    public function assignRider(PickupRun $run, int $riderUserId, array $data, User $actor): PickupRun
    {
        $this->assertEditable($run);
        $this->validateVehicle($data['vehicle_type'] ?? null);

        $rider = User::query()->findOrFail($riderUserId);

        $sameRider = (int) $run->rider_user_id === (int) $rider->id;

        DB::transaction(function () use ($run, $rider, $data, $actor, $sameRider) {
            $run->update([
                'rider_user_id' => $rider->id,
                'vehicle_type' => $data['vehicle_type'] ?? $run->vehicle_type,
                'vehicle_number' => $data['vehicle_number'] ?? $run->vehicle_number,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $run->notes,
                'status' => PickupRun::STATUS_ASSIGNED,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
            ]);

            // Assign individual pickup requests to rider
            $requestIds = $run->activeItems()->pluck('pickup_request_id')->all();
            if (! empty($requestIds)) {
                PickupRequest::query()->whereIn('id', $requestIds)
                    ->when($sameRider, fn ($q) => $q->whereIn('status', ['requested', 'pending', PickupStatus::ASSIGNED]))
                    ->when(! $sameRider, fn ($q) => $q->whereIn('status', ['requested', 'pending', PickupStatus::ASSIGNED, PickupStatus::ACCEPTED, PickupStatus::STARTED]))
                    ->update([
                        'assigned_to' => $rider->id,
                        'assigned_by' => $actor->id,
                        'assigned_at' => now(),
                        'status' => PickupStatus::ASSIGNED,
                    ]);

                DB::table('pickup_request_shipments')
                    ->join('shipments', 'shipments.id', '=', 'pickup_request_shipments.shipment_id')
                    ->whereIn('pickup_request_shipments.pickup_request_id', $requestIds)
                    ->whereNull('pickup_request_shipments.removed_at')
                    ->where('shipments.status', CourierStatus::AWAITING_PICKUP)
                    ->update([
                        'shipments.status' => CourierStatus::PICKUP_ASSIGNED,
                        'shipments.updated_at' => now(),
                    ]);
            }
        });

        try {
            $requestIds = $run->activeItems()->pluck('pickup_request_id')->all();
            if (! empty($requestIds) && class_exists(\App\Events\PickupAssigned::class)) {
                $pickups = PickupRequest::whereIn('id', $requestIds)->get();
                foreach ($pickups as $p) {
                    event(new \App\Events\PickupAssigned($p));
                }
            }
        } catch (\Throwable $e) {}

        return $run->fresh(['branch', 'rider', 'items']);
    }

    public function sequence(PickupRun $run, ?array $itemIds = null): PickupRun
    {
        $this->assertEditable($run);

        if ($itemIds === null) {
            $this->autoSequence($run);
        } else {
            $items = $run->activeItems()->get()->keyBy('id');
            $seq = 1;
            DB::transaction(function () use ($itemIds, $items, &$seq) {
                foreach ($itemIds as $id) {
                    if ($item = $items->get((int) $id)) {
                        $item->update(['sequence' => $seq++]);
                    }
                }
            });
        }

        return $run->fresh(['items']);
    }

    public function dispatch(PickupRun $run, User $actor, array $meta = []): PickupRun
    {
        $this->assertEditable($run);
        if (! $run->rider_user_id) {
            throw ValidationException::withMessages(['rider_user_id' => ['Assign a rider before dispatching.']]);
        }
        if ($run->activeItems()->count() === 0) {
            throw ValidationException::withMessages(['items' => ['Add at least one pickup request before dispatching.']]);
        }

        DB::transaction(function () use ($run, $actor, $meta) {
            $run->update([
                'status' => PickupRun::STATUS_IN_PROGRESS,
                'dispatched_by' => $actor->id,
                'dispatched_at' => now(),
                'vehicle_type' => $meta['vehicle_type'] ?? $run->vehicle_type,
                'vehicle_number' => $meta['vehicle_number'] ?? $run->vehicle_number,
            ]);

            $run->activeItems()->update(['status' => PickupRunItem::STATUS_IN_PROGRESS]);
        });

        // Starting a run = the rider accepted and started every pickup on it.
        // Each pickup goes through the same accept/start service calls as the
        // manual buttons (timestamps, shipment tracking, merchant callbacks).
        $this->lastStartResults = $this->advanceRunPickups($run->fresh(), $actor);

        return $run->fresh(['branch', 'rider', 'items']);
    }

    public function startByRider(PickupRun $run, User $actor): PickupRun
    {
        abort_unless((int) $run->rider_user_id === (int) $actor->id, 403, 'You are not assigned to this pickup run.');

        if ($run->status === PickupRun::STATUS_ASSIGNED) {
            $this->dispatch($run, $actor);
        } elseif ($run->status === PickupRun::STATUS_IN_PROGRESS) {
            $this->lastStartResults = $this->advanceRunPickups($run, $actor);
        }

        return $run->fresh(['branch', 'rider', 'items']);
    }

    /** Per-stop outcome of the last dispatch/start/sync (for API responses). */
    public array $lastStartResults = [];

    /**
     * Move every open pickup on the run to "started" for the run's rider:
     * requested/pending -> assigned (assign), assigned -> accepted (accept),
     * accepted -> started (start). Arrived, collected, failed, cancelled and
     * later stages are never touched. Each pickup is its own transaction (as
     * with the manual buttons) so one bad pickup does not block the others;
     * failures are reported per stop.
     *
     * @return list<array{item_id:int,pickup_request_id:int,request_number:?string,from:string,to:string,changed:bool,ok:bool,error:?string}>
     */
    public function advanceRunPickups(PickupRun $run, User $actor, ?array $onlyPickupRequestIds = null): array
    {
        if (! in_array($run->status, [PickupRun::STATUS_IN_PROGRESS], true) || ! $run->rider_user_id) {
            return [];
        }
        $rider = User::query()->find($run->rider_user_id);
        if (! $rider) {
            return [];
        }
        $service = app(PickupRequestService::class);
        $only = $onlyPickupRequestIds !== null ? array_map('intval', $onlyPickupRequestIds) : null;

        $items = PickupRunItem::query()->where('pickup_run_id', $run->id)
            ->where('status', '!=', PickupRunItem::STATUS_REMOVED)
            ->orderBy('sequence')->orderBy('id')->get();

        $results = [];
        foreach ($items as $item) {
            if ($only !== null && ! in_array((int) $item->pickup_request_id, $only, true)) {
                continue;
            }
            $pr = PickupRequest::query()->find($item->pickup_request_id);
            if (! $pr) {
                continue;
            }
            $from = $this->prStatus($pr);
            $row = [
                'item_id' => (int) $item->id,
                'pickup_request_id' => (int) $pr->id,
                'request_number' => $pr->request_number,
                'from' => $from,
                'to' => $from,
                'changed' => false,
                'ok' => true,
                'error' => null,
            ];
            if (! in_array($from, ['requested', 'pending', PickupStatus::ASSIGNED, PickupStatus::ACCEPTED], true)) {
                $results[] = $row; // already started/arrived/collected/failed/...: leave as is

                continue;
            }

            $errors = [];
            // a) make sure the run's rider owns the pickup
            $status = $this->prStatus($pr);
            if (in_array($status, ['requested', 'pending'], true)
                || ($status === PickupStatus::ASSIGNED && (int) $pr->assigned_to !== (int) $rider->id)) {
                try {
                    $service->assign($pr, $rider, $actor);
                } catch (ValidationException $e) {
                    // Rider from a parent/sub branch of the run: keep the run's own assignment.
                    $this->forceAssign($pr, $rider, $actor);
                } catch (\Throwable $e) {
                    $errors[] = $e->getMessage();
                }
                $pr->refresh();
            } elseif ($status === PickupStatus::ACCEPTED && (int) $pr->assigned_to !== (int) $rider->id) {
                $errors[] = 'Accepted by another rider. Reassign it to '.$rider->name.' first.';
            }

            // b) accept, c) start (the same calls as the manual buttons)
            foreach ([PickupStatus::ASSIGNED => 'accept', PickupStatus::ACCEPTED => 'start'] as $need => $method) {
                if ($this->prStatus($pr) !== $need || (int) $pr->assigned_to !== (int) $rider->id) {
                    continue;
                }
                try {
                    $service->{$method}($pr, $rider);
                } catch (ValidationException $e) {
                    $errors[] = (string) (collect($e->errors())->flatten()->first() ?: $e->getMessage());
                } catch (\Throwable $e) {
                    $errors[] = $e->getMessage();
                }
                $pr->refresh();
            }

            $row['to'] = $this->prStatus($pr);
            $row['changed'] = $row['to'] !== $from;
            $row['ok'] = $row['to'] === PickupStatus::STARTED;
            $row['error'] = $errors ? implode(' ', array_unique($errors)) : null;
            if ($errors) {
                Log::warning('pickup_run.advance_pickup_issue', ['run_id' => $run->id, 'pickup_request_id' => $pr->id, 'from' => $from, 'to' => $row['to'], 'errors' => $errors]);
            }
            $results[] = $row;
        }

        return $results;
    }

    /**
     * Bring in-progress runs whose pickups are still requested/assigned/accepted
     * up to "started" (repairs runs started before run-start advanced pickups).
     * Idempotent: does nothing when every pickup is already started or later.
     *
     * @return array<string, list<array>> run_number => per-stop results (changed runs only)
     */
    public function syncLaggingRuns(?int $riderUserId = null, ?int $runId = null): array
    {
        $runs = PickupRun::query()->where('status', PickupRun::STATUS_IN_PROGRESS)
            ->whereNotNull('rider_user_id')
            ->when($riderUserId, fn ($q) => $q->where('rider_user_id', $riderUserId))
            ->when($runId, fn ($q) => $q->where('id', $runId))
            ->whereExists(function ($q) {
                $q->selectRaw('1')->from('pickup_run_items as pri')
                    ->join('pickup_requests as preq', 'preq.id', '=', 'pri.pickup_request_id')
                    ->whereColumn('pri.pickup_run_id', 'pickup_runs.id')
                    ->where('pri.status', '!=', PickupRunItem::STATUS_REMOVED)
                    ->whereIn('preq.status', ['requested', 'pending', PickupStatus::ASSIGNED, PickupStatus::ACCEPTED]);
            })
            ->get();

        $out = [];
        foreach ($runs as $run) {
            $rider = User::query()->find($run->rider_user_id);
            if (! $rider) {
                continue;
            }
            $res = $this->advanceRunPickups($run, $rider);
            if (collect($res)->contains(fn ($r) => $r['changed'] || ! $r['ok'])) {
                $out[$run->run_number] = $res;
            }
        }

        return $out;
    }

    /** Short summary for toasts, e.g. "2 pickups accepted and started, 1 failed (PR-000020: ...)". */
    public function startSummary(array $results): string
    {
        $moved = collect($results)->filter(fn ($r) => $r['changed'] && $r['ok'])->count();
        $bad = collect($results)->filter(fn ($r) => ! $r['ok'] && in_array($r['from'], ['requested', 'pending', 'assigned', 'accepted'], true));
        $text = $moved > 0
            ? $moved.' pickup'.($moved === 1 ? '' : 's').' accepted and started'
            : 'All pickups are already started';
        if ($bad->isNotEmpty()) {
            $text .= ', '.$bad->count().' could not be started ('.$bad->map(fn ($r) => $r['request_number'].': '.($r['error'] ?: $r['to']))->implode('; ').')';
        }

        return $text;
    }

    private function prStatus(PickupRequest $pr): string
    {
        return strtolower((string) ($pr->status instanceof \BackedEnum ? $pr->status->value : ($pr->status ?? '')));
    }

    private function forceAssign(PickupRequest $pr, User $rider, User $actor): void
    {
        $pr->forceFill([
            'assigned_to' => $rider->id,
            'assigned_by' => $actor->id,
            'assigned_at' => now(),
            'status' => PickupStatus::ASSIGNED,
        ])->save();

        DB::table('pickup_request_shipments')
            ->join('shipments', 'shipments.id', '=', 'pickup_request_shipments.shipment_id')
            ->where('pickup_request_shipments.pickup_request_id', $pr->id)
            ->whereNull('pickup_request_shipments.removed_at')
            ->where('shipments.status', CourierStatus::AWAITING_PICKUP)
            ->update(['shipments.status' => CourierStatus::PICKUP_ASSIGNED, 'shipments.updated_at' => now()]);
    }

    /**
     * Rider finishes the pickup run once every stop is picked up, failed or
     * removed. Uses the same recount() completion path as live sync, so the
     * run lands in "completed" for the branch to receive parcels and close.
     */
    public function finishByRider(PickupRun $run, User $actor): PickupRun
    {
        abort_unless((int) $run->rider_user_id === (int) $actor->id, 403, 'You are not assigned to this pickup run.');

        if (! in_array($run->status, [PickupRun::STATUS_IN_PROGRESS, PickupRun::STATUS_COMPLETED], true)) {
            throw ValidationException::withMessages(['run' => ['Only a started pickup run can be finished (current status: '.$run->status.').']]);
        }

        DB::transaction(function () use ($run, $actor) {
            $items = PickupRunItem::query()->where('pickup_run_id', $run->id)
                ->where('status', '!=', PickupRunItem::STATUS_REMOVED)->orderBy('sequence')->get();

            // Pull the latest pickup outcome into each stop first.
            foreach ($items as $item) {
                $this->syncFromPickupRequest((int) $item->pickup_request_id);
            }
            $items = $items->map(fn (PickupRunItem $i) => $i->fresh());

            $open = $items->filter(fn (PickupRunItem $i) => ! in_array($i->status, PickupRunItem::TERMINAL_STATUSES, true));
            if ($open->isNotEmpty()) {
                $numbers = PickupRequest::query()->whereIn('id', $open->pluck('pickup_request_id'))->pluck('request_number', 'id');
                $list = $open->map(fn (PickupRunItem $i) => '#'.$i->sequence.' '.($numbers[$i->pickup_request_id] ?? ('PR '.$i->pickup_request_id)))->implode(', ');
                throw ValidationException::withMessages(['items' => [
                    $open->count().' stop'.($open->count() === 1 ? '' : 's')." still open: {$list}. Collect or fail each stop before finishing the run.",
                ]]);
            }

            $run->refresh();
            $this->recount($run);
            $run->refresh();
            if ($run->status !== PickupRun::STATUS_COMPLETED) {
                $run->update(['status' => PickupRun::STATUS_COMPLETED, 'completed_at' => $run->completed_at ?: now()]);
            }
            Log::info('pickup_run.finished_by_rider', ['run_id' => $run->id, 'rider_id' => $actor->id]);
        });

        return $run->fresh(['branch', 'rider', 'items']);
    }

    public function close(PickupRun $run, array $data, User $actor): PickupRun
    {
        if (in_array($run->status, [PickupRun::STATUS_CLOSED, PickupRun::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages(['run' => ['Run is already closed or cancelled.']]);
        }

        DB::transaction(function () use ($run, $data, $actor) {
            $run->update([
                'status' => PickupRun::STATUS_CLOSED,
                'close_notes' => $data['notes'] ?? null,
                'closed_by' => $actor->id,
                'closed_at' => now(),
            ]);

            $this->recount($run);
        });

        return $run->fresh(['branch', 'rider', 'items']);
    }

    public function cancel(PickupRun $run, ?string $reason, User $actor): PickupRun
    {
        if (in_array($run->status, [PickupRun::STATUS_CLOSED, PickupRun::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages(['run' => ['Run is already closed or cancelled.']]);
        }

        DB::transaction(function () use ($run, $reason, $actor) {
            $run->update([
                'status' => PickupRun::STATUS_CANCELLED,
                'cancel_reason' => $reason,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
            ]);

            $run->activeItems()->update(['status' => PickupRunItem::STATUS_REMOVED, 'reason' => 'Run cancelled']);

            if (Schema::hasColumn('pickup_requests', 'pickup_run_id')) {
                PickupRequest::query()->where('pickup_run_id', $run->id)->update(['pickup_run_id' => null]);
            }

            $allPids = $run->items()->pluck('pickup_request_id')->all();
            if (! empty($allPids)) {
                PickupRequest::query()->whereIn('id', $allPids)
                    ->where('status', PickupStatus::ASSIGNED)
                    ->update([
                        'assigned_to' => null,
                        'assigned_by' => null,
                        'assigned_at' => null,
                        'status' => PickupStatus::REQUESTED,
                    ]);

                DB::table('pickup_request_shipments')
                    ->join('shipments', 'shipments.id', '=', 'pickup_request_shipments.shipment_id')
                    ->whereIn('pickup_request_shipments.pickup_request_id', $allPids)
                    ->whereNull('pickup_request_shipments.removed_at')
                    ->where('shipments.status', CourierStatus::PICKUP_ASSIGNED)
                    ->update([
                        'shipments.status' => CourierStatus::AWAITING_PICKUP,
                        'shipments.updated_at' => now(),
                    ]);
            }
        });

        return $run->fresh(['branch', 'rider', 'items']);
    }

    /**
     * Keep PickupRun and its items live-synced when rider updates a pickup
     * (e.g. arrived at store, collected/completed, failed).
     */
    public function syncFromPickupRequest(int|PickupRequest $pickupRequest): void
    {
        if (! Schema::hasTable('pickup_run_items') || ! Schema::hasTable('pickup_runs')) {
            return;
        }

        $id = $pickupRequest instanceof PickupRequest ? (int) $pickupRequest->id : (int) $pickupRequest;
        $pr = $pickupRequest instanceof PickupRequest ? $pickupRequest : PickupRequest::query()->find($id);
        if (! $pr) {
            return;
        }

        $items = PickupRunItem::query()
            ->where('pickup_request_id', $id)
            ->where('status', '!=', PickupRunItem::STATUS_REMOVED)
            ->whereHas('run', fn ($q) => $q->whereIn('status', [PickupRun::STATUS_OPEN, PickupRun::STATUS_ASSIGNED, PickupRun::STATUS_IN_PROGRESS, PickupRun::STATUS_COMPLETED]))
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $prStatus = strtolower((string) ($pr->status?->value ?? $pr->status ?? ''));

        foreach ($items as $item) {
            $update = [];

            if (in_array($prStatus, ['rider_arrived', 'arrived', 'collecting'], true)) {
                $update['status'] = PickupRunItem::STATUS_ARRIVED;
                $update['arrived_at'] = $item->arrived_at ?: now();
            } elseif (in_array($prStatus, ['completed', 'collected', 'picked_up', 'on_way_to_branch', 'received_at_origin', 'received'], true)) {
                $update['status'] = PickupRunItem::STATUS_PICKED_UP;
                $update['arrived_at'] = $item->arrived_at ?: now();
                $update['picked_up_at'] = $item->picked_up_at ?: ($pr->completed_at ?: now());
            } elseif (in_array($prStatus, ['failed', 'cancelled'], true)) {
                $update['status'] = PickupRunItem::STATUS_FAILED;
                $update['failed_at'] = $item->failed_at ?: now();
            }

            if (! empty($update)) {
                $item->update($update);
            }

            if ($item->run) {
                $this->recount($item->run);
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Summarize & Format                                                  */
    /* ------------------------------------------------------------------ */

    public function summarize(PickupRun $run, bool $withItems = false): array
    {
        $run->loadMissing([
            'branch:id,name,code,phone,address,latitude,longitude',
            'rider:id,name,phone',
        ]);

        $riderLocation = null;
        $isOutForPickup = in_array($run->status, ['out_for_pickup', 'in_progress'], true);

        if ($isOutForPickup && ($run->id || $run->rider_user_id)) {
            $cached = $run->id ? \Illuminate\Support\Facades\Cache::get("run:pickup:{$run->id}:location") : null;
            if (!$cached && $run->rider_user_id) {
                $riderLoc = \Illuminate\Support\Facades\Cache::get("rider:{$run->rider_user_id}:location");
                if ($riderLoc && is_array($riderLoc)) {
                    $cachedRunId = (int) ($riderLoc['run_id'] ?? 0);
                    if ($cachedRunId === 0 || $cachedRunId === (int) $run->id) {
                        $cached = $riderLoc;
                    }
                }
            }

            if ($cached && is_array($cached) && !empty($cached['lat']) && !empty($cached['lng'])) {
                $updatedAtStr = $cached['updated_at'] ?? null;
                $diffSeconds = 99999;
                if ($updatedAtStr) {
                    try {
                        $diffSeconds = abs(\Carbon\Carbon::parse($updatedAtStr)->diffInSeconds(now()));
                    } catch (\Throwable $e) {}
                }
                $isLive = ($diffSeconds <= 120);
                $isRecent = ($diffSeconds <= 900);

                $riderLocation = [
                    'lat' => (float) $cached['lat'],
                    'lng' => (float) $cached['lng'],
                    'heading' => isset($cached['heading']) && is_numeric($cached['heading']) ? (float) $cached['heading'] : null,
                    'speed' => isset($cached['speed']) && is_numeric($cached['speed']) ? (float) $cached['speed'] : null,
                    'updated_at' => $updatedAtStr,
                    'seconds_ago' => $diffSeconds,
                    'is_live' => $isLive,
                    'is_recent' => $isRecent,
                ];
            }
        }

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
            'rider' => $run->rider ? [
                'id' => $run->rider->id,
                'name' => $run->rider->name,
                'phone' => $run->rider->phone,
            ] : null,
            'rider_location' => $riderLocation,
            'vehicle_type' => $run->vehicle_type,
            'vehicle_number' => $run->vehicle_number,
            'area_label' => $run->area_label,
            'notes' => $run->notes,
            'expected_count' => (int) $run->expected_count,
            'picked_up_count' => (int) $run->picked_up_count,
            'failed_count' => (int) $run->failed_count,
            'pending_count' => max(0, (int) $run->expected_count - (int) $run->picked_up_count - (int) $run->failed_count),
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
            $items = PickupRunItem::query()->where('pickup_run_id', $run->id)
                ->with(['pickupRequest.merchant', 'pickupRequest.shipments'])
                ->orderByRaw("CASE WHEN status = 'removed' THEN 1 ELSE 0 END")
                ->orderBy('sequence')->orderBy('id')->get();
            $out['items'] = $items->map(fn (PickupRunItem $i) => $this->itemRow($i))->values()->all();
        }

        return $out;
    }

    public function itemRow(PickupRunItem $item): array
    {
        $pr = $item->pickupRequest;

        return array_merge($pr ? $this->pickupRow($pr) : ['pickup_request_id' => $item->pickup_request_id], [
            'id' => $item->id,
            'item_id' => $item->id,
            'pickup_run_id' => $item->pickup_run_id,
            'pickup_request_id' => $item->pickup_request_id,
            // Live pickup request status (assigned/accepted/started/arrived/collected/...)
            // so rider stop cards can offer the same next action as the legacy jobs screen.
            'pickup_status' => $pr ? ($pr->status instanceof \BackedEnum ? $pr->status->value : $pr->status) : null,
            'sequence' => (int) $item->sequence,
            'status' => $item->status,
            'reason' => $item->reason,
            'arrived_at' => $item->arrived_at?->toIso8601String(),
            'picked_up_at' => $item->picked_up_at?->toIso8601String(),
            'failed_at' => $item->failed_at?->toIso8601String(),
        ]);
    }

    public function pickupRow(PickupRequest $pr): array
    {
        $shipments = [];
        if ($pr->relationLoaded('shipments') && $pr->shipments) {
            $shipments = $pr->shipments->map(function ($s) {
                return [
                    'id' => $s->id,
                    'tracking_number' => $s->tracking_number,
                    'merchant_order_id' => $s->merchant_order_id,
                    'status' => $s->status,
                    'receiver_name' => $s->receiver_name,
                    'receiver_phone' => $s->receiver_phone,
                    'receiver_address' => $s->receiver_address,
                    'receiver_city' => $s->receiver_city,
                    'cod_amount' => (float) ($s->cod_amount ?? 0),
                    'collection_status' => $s->pivot?->collection_status ?? null,
                    'collected_at' => $s->pivot?->collected_at ? (string) $s->pivot->collected_at : null,
                    'pivot_status' => $s->pivot?->status ?? null,
                ];
            })->values()->all();
        }

        return [
            'pickup_request_id' => $pr->id,
            'request_number' => $pr->request_number,
            'status' => $pr->status,
            'merchant' => $pr->relationLoaded('merchant') && $pr->merchant ? [
                'id' => $pr->merchant->id,
                'name' => $pr->merchant->name,
                'business_name' => $pr->merchant->business_name ?? $pr->merchant->company_name ?? $pr->merchant->store_name ?? $pr->merchant->name,
                'phone' => $pr->merchant->phone,
            ] : null,
            'pickup_name' => $pr->pickup_name,
            'pickup_phone' => $pr->pickup_phone,
            'pickup_address' => $pr->pickup_address,
            'pickup_city' => $pr->pickup_city,
            'pickup_area' => $pr->pickup_area,
            'area' => trim((string) ($pr->pickup_area ?: $pr->pickup_city ?: '')) ?: null,
            'pickup_lat' => $pr->pickup_lat ? (float) $pr->pickup_lat : null,
            'pickup_lng' => $pr->pickup_lng ? (float) $pr->pickup_lng : null,
            'parcel_quantity' => (int) $pr->parcel_quantity,
            'preferred_pickup_at' => $pr->preferred_pickup_at?->toIso8601String(),
            'branch_id' => (int) ($pr->pickup_sub_branch_id ?: $pr->pickup_branch_id ?: $pr->branch_id ?: 0),
            'shipments' => $shipments,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Helpers & Sequencing                                               */
    /* ------------------------------------------------------------------ */

    private const KATHMANDU_HUBS = [
        ['sallaghari', 27.6718, 85.4286],
        ['bhaktapur', 27.6710, 85.4298],
        ['suryabinayak', 27.6710, 85.4298],
        ['sanothimi', 27.6812, 85.3705],
        ['thimi', 27.6722, 85.3868],
        ['balkot', 27.6644, 85.3615],
        ['mid baneshwor', 27.6955, 85.3375],
        ['middle baneshwor', 27.6955, 85.3375],
        ['new baneshwor', 27.6915, 85.3420],
        ['old baneshwor', 27.6990, 85.3330],
        ['baneshwor', 27.6916, 85.3422],
        ['koteshwor', 27.6792, 85.3494],
        ['tinkune', 27.6865, 85.3465],
        ['gaushala', 27.7070, 85.3460],
        ['chabahil', 27.7172, 85.3468],
        ['sinamangal', 27.6960, 85.3500],
        ['battisputali', 27.7042, 85.3418],
        ['jorpati', 27.7200, 85.3750],
        ['bouddha', 27.7215, 85.3620],
        ['maitighar', 27.6922, 85.3218],
        ['tripureshwor', 27.6953, 85.3134],
        ['kalanki', 27.6934, 85.2815],
        ['thamel', 27.7154, 85.3123],
        ['lazimpat', 27.7212, 85.3197],
        ['balaju', 27.7329, 85.3025],
        ['maharajgunj', 27.7364, 85.3308],
        ['kupandole', 27.6872, 85.3168],
        ['pulchowk', 27.6784, 85.3162],
        ['jawalakhel', 27.6728, 85.3155],
        ['lagankhel', 27.6672, 85.3242],
        ['satdobato', 27.6575, 85.3255],
        ['gwarko', 27.6660, 85.3340],
        ['mahalaxmi', 27.6660, 85.3340],
        ['balkhu', 27.6851, 85.2974],
        ['kalimati', 27.6987, 85.2996],
        ['dillibazar', 27.7047, 85.3275],
        ['putalisadak', 27.7031, 85.3212],
        ['anamnagar', 27.6975, 85.3262],
        ['gyaneshwor', 27.7088, 85.3341],
        ['patan', 27.6728, 85.3255],
        ['lalitpur', 27.6672, 85.3242],
    ];

    private function resolvePickupCoordinates($pr): ?array
    {
        if (! $pr) {
            return null;
        }
        $lat = (float) ($pr->pickup_lat ?? 0);
        $lng = (float) ($pr->pickup_lng ?? 0);
        if ($lat !== 0.0 && $lng !== 0.0) {
            return ['lat' => $lat, 'lng' => $lng];
        }

        $text = Str::lower(trim(($pr->pickup_area ?? '') . ' ' . ($pr->pickup_address ?? '') . ' ' . ($pr->pickup_city ?? '')));
        foreach (self::KATHMANDU_HUBS as $hub) {
            if (str_contains($text, $hub[0])) {
                return ['lat' => (float) $hub[1], 'lng' => (float) $hub[2]];
            }
        }

        return null;
    }

    private function haversineDistanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng / 2) * sin($dLng / 2);
        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Orders pickup run items along the most efficient geographic route.
     * Uses Nearest-Neighbor TSP starting at the Branch office origin.
     */
    private function autoSequence(PickupRun $run): void
    {
        $items = PickupRunItem::query()->where('pickup_run_id', $run->id)
            ->where('status', '!=', PickupRunItem::STATUS_REMOVED)
            ->with('pickupRequest:id,pickup_area,pickup_city,pickup_address,pickup_lat,pickup_lng')
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        // 1. Resolve branch origin coordinates
        $branch = $run->branch;
        $currLat = (float) ($branch?->latitude ?: 0);
        $currLng = (float) ($branch?->longitude ?: 0);
        if ($currLat === 0.0 || $currLng === 0.0) {
            $currLat = 27.6915;
            $currLng = 85.3420;
        }

        // 2. Separate items into coordinate-resolvable vs non-resolvable
        $withCoords = [];
        $withoutCoords = [];

        foreach ($items as $item) {
            $coords = $this->resolvePickupCoordinates($item->pickupRequest);
            if ($coords) {
                $withCoords[] = [
                    'item' => $item,
                    'lat' => $coords['lat'],
                    'lng' => $coords['lng'],
                ];
            } else {
                $withoutCoords[] = $item;
            }
        }

        // 3. Nearest-Neighbor Traveling Salesperson Path from Branch
        $orderedItems = [];
        while (! empty($withCoords)) {
            $bestIdx = 0;
            $bestDist = PHP_FLOAT_MAX;

            foreach ($withCoords as $idx => $entry) {
                $d = $this->haversineDistanceKm($currLat, $currLng, $entry['lat'], $entry['lng']);
                if ($d < $bestDist) {
                    $bestDist = $d;
                    $bestIdx = $idx;
                }
            }

            $chosen = $withCoords[$bestIdx];
            $orderedItems[] = $chosen['item'];
            $currLat = $chosen['lat'];
            $currLng = $chosen['lng'];
            array_splice($withCoords, $bestIdx, 1);
        }

        // 4. Any remaining items without coordinates sorted by area
        if (! empty($withoutCoords)) {
            $sortedRemaining = collect($withoutCoords)->sortBy(fn (PickupRunItem $i) => [
                Str::lower(trim((string) ($i->pickupRequest?->pickup_area ?: $i->pickupRequest?->pickup_city ?: 'zzz'))),
                Str::lower(trim((string) ($i->pickupRequest?->pickup_city ?? ''))),
                Str::lower(trim((string) ($i->pickupRequest?->pickup_address ?? ''))),
                $i->id,
            ])->values();
            foreach ($sortedRemaining as $rem) {
                $orderedItems[] = $rem;
            }
        }

        // 5. Update sequence 1..N
        foreach ($orderedItems as $idx => $item) {
            $seq = $idx + 1;
            if ((int) $item->sequence !== $seq) {
                $item->update(['sequence' => $seq]);
            }
        }
    }

    private function resequence(PickupRun $run): void
    {
        $items = PickupRunItem::query()->where('pickup_run_id', $run->id)
            ->where('status', '!=', PickupRunItem::STATUS_REMOVED)->orderBy('sequence')->orderBy('id')->get();
        foreach ($items as $idx => $item) {
            if ((int) $item->sequence !== $idx + 1) {
                $item->update(['sequence' => $idx + 1]);
            }
        }
    }

    private function recount(PickupRun $run): void
    {
        $items = PickupRunItem::query()->where('pickup_run_id', $run->id)
            ->where('status', '!=', PickupRunItem::STATUS_REMOVED)->get();

        $expected = $items->count();
        $pickedUp = $items->where('status', PickupRunItem::STATUS_PICKED_UP)->count();
        $failed = $items->where('status', PickupRunItem::STATUS_FAILED)->count();

        $status = $run->status;
        if ($expected > 0 && ($pickedUp + $failed) >= $expected && ! in_array($status, [PickupRun::STATUS_CLOSED, PickupRun::STATUS_CANCELLED], true)) {
            $status = PickupRun::STATUS_COMPLETED;
        }

        $run->update([
            'expected_count' => $expected,
            'picked_up_count' => $pickedUp,
            'failed_count' => $failed,
            'status' => $status,
            'completed_at' => $status === PickupRun::STATUS_COMPLETED ? ($run->completed_at ?: now()) : null,
        ]);
    }

    private function nextRunNumber(int $branchId): string
    {
        // Format: RPR-01, RPR-02, RPR-03...
        $last = DB::table('pickup_runs')
            ->where(function ($w) {
                $w->where('run_number', 'like', 'RPR-%')
                  ->orWhere('run_number', 'like', 'PR-RUN-%');
            })
            ->orderByRaw('LENGTH(run_number) DESC')
            ->orderByDesc('run_number')
            ->lockForUpdate()
            ->value('run_number');

        $number = ((is_string($last) && preg_match('/(\d+)$/', $last, $m)) ? (int) $m[1] : 0) + 1;

        do {
            $candidate = sprintf('RPR-%02d', $number);
            $exists = DB::table('pickup_runs')->where('run_number', $candidate)->exists();
            $number++;
        } while ($exists);

        return $candidate;
    }

    public function receiveRunShipment(PickupRun $run, int $pickupRequestId, int $shipmentId, User $staff, array $data = []): array
    {
        $item = PickupRunItem::query()->where('pickup_run_id', $run->id)
            ->where('pickup_request_id', $pickupRequestId)
            ->where('status', '!=', PickupRunItem::STATUS_REMOVED)
            ->firstOrFail();

        $pr = PickupRequest::query()->findOrFail($pickupRequestId);
        $shipment = \Modules\Shipment\Models\Shipment::query()->findOrFail($shipmentId);

        $service = app(PickupRequestService::class);
        $res = $service->receiveShipment($pr, $shipment, $staff, $data);

        $this->syncFromPickupRequest($pr->fresh());

        return [
            'item' => $res,
            'run' => $this->summarize($run->fresh(), true),
        ];
    }

    public function receiveAllRunShipments(PickupRun $run, User $staff): array
    {
        $items = PickupRunItem::query()->where('pickup_run_id', $run->id)
            ->where('status', '!=', PickupRunItem::STATUS_REMOVED)
            ->with(['pickupRequest.shipments'])
            ->get();

        $service = app(PickupRequestService::class);
        $receivedCount = 0;
        $errors = [];

        foreach ($items as $item) {
            $pr = $item->pickupRequest;
            if (! $pr) continue;

            $shipments = $pr->shipments;
            foreach ($shipments as $shipment) {
                if ($shipment->pivot && $shipment->pivot->status === \Modules\Pickup\Support\PickupShipmentStatus::RECEIVED) {
                    continue;
                }
                try {
                    $service->receiveShipment($pr, $shipment, $staff);
                    $receivedCount++;
                } catch (\Throwable $e) {
                    $errors[] = "Shipment {$shipment->tracking_number}: {$e->getMessage()}";
                }
            }

            $this->syncFromPickupRequest($pr->fresh());
        }

        return [
            'received_count' => $receivedCount,
            'errors' => $errors,
            'run' => $this->summarize($run->fresh(), true),
        ];
    }

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

    private function userName(int $id): string
    {
        return (string) (User::query()->where('id', $id)->value('name') ?: 'User #'.$id);
    }

    private function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn ($v) => $v > 0)));
    }

    private function assertEditable(PickupRun $run): void
    {
        if (! $run->isEditable()) {
            throw ValidationException::withMessages(['run' => ["{$run->run_number} is {$run->status} and cannot be modified."]]);
        }
    }

    private function validateVehicle(?string $type): void
    {
        if ($type !== null && $type !== '' && ! in_array($type, PickupRun::VEHICLE_TYPES, true)) {
            throw ValidationException::withMessages(['vehicle_type' => ['Vehicle type must be one of: '.implode(', ', PickupRun::VEHICLE_TYPES).'.']]);
        }
    }
}

