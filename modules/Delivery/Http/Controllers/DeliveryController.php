<?php

namespace Modules\Delivery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\CourierStatus;
use Illuminate\Http\Request;
use Modules\Delivery\Models\DeliveryAssignment;
use Modules\Delivery\Models\DeliveryRun;
use Modules\Delivery\Services\DeliveryRunService;
use Modules\Delivery\Services\DeliveryWorkflowService;
use Modules\Settlement\Services\SettlementWorkflowService;
use Modules\Shipment\Models\Shipment;

class DeliveryController extends Controller
{
    public function __construct(
        private readonly DeliveryWorkflowService $service,
        private readonly DeliveryRunService $runs,
    ) {}

    /**
     * Admin / branch-manager delivery board.
     *
     * Returns delivery assignments (pending → delivered/failed) with the
     * shipment, rider and route context needed to triage last-mile work.
     * Each row carries `delivery_run` (DR number / status) when the parcel
     * is on a delivery run.
     */
    public function index(Request $request)
    {
        $query = DeliveryAssignment::query()
            ->with([
                'shipment.merchant:id,name,code,external_store_id,marketplace_id',
                'shipment.merchant.marketplace:id,name,code',
                'shipment.originBranch',
                'shipment.destinationBranch',
                'rider',
            ]);

        $this->applyScope($query, $request);
        $this->applyFilters($query, $request);

        $this->applyStatusFilter($query, $request);

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
        $page = $query->latest('id')->paginate($perPage);
        $this->runs->attachRunInfo($page->getCollection());
        $this->attachHandlingInfo($page->getCollection(), $request);

        return ApiResponse::success($page);
    }

    /**
     * Summary counts for the delivery board tabs. Uses the same branch scope
     * and filters (type, search, merchant) as the list so the chips match it.
     */
    public function summary(Request $request)
    {
        $base = DeliveryAssignment::query();
        $this->applyScope($base, $request);
        $this->applyFilters($base, $request);

        $byStatus = (clone $base)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($v) => (int) $v);

        // "Ready to assign" = pending and not on an active DR; "planned" =
        // pending but already on a DR (rider set on the run, not dispatched yet).
        $ready = (clone $base)->where('status', 'pending')->whereNotExists($this->activeRunItem())->count();
        $planned = (int) ($byStatus['pending'] ?? 0) - $ready;

        return ApiResponse::success([
            'total' => (int) $byStatus->sum(),
            'by_status' => $byStatus,
            'ready' => $ready,
            'planned' => max(0, $planned),
        ]);
    }

    /**
     * Who sees which deliveries:
     *  - HQ (super_admin, main_admin, admin): every branch, or ?branch_id= (with its sub-branches)
     *  - branch managers / branch staff who can assign deliveries: their branch and sub-branches
     *  - everyone else (riders): only their own deliveries
     */
    private function applyScope($query, Request $request): void
    {
        $user = $request->user();

        if ($this->isHq($user)) {
            $branchId = (int) $request->input('branch_id', 0);
            if ($branchId > 0) {
                $this->whereBranch($query, $this->runs->branchFamily($branchId));
            }

            return;
        }

        $branchId = (int) ($user->branch_id ?? 0);
        if ($branchId > 0 && $this->isBranchOperator($user)) {
            $this->whereBranch($query, $this->runs->branchFamily($branchId));

            return;
        }

        $query->where('rider_id', $user->id);
    }

    /**
     * A branch sees:
     *  - deliveries it handles (delivery_assignments.branch_id / sub_branch_id), and
     *  - deliveries whose FINAL destination is the branch (shipments.destination_*)
     *    but that another branch handles, e.g. a parcel the origin branch sorted
     *    for last mile and delivered with its own rider. Those rows are view-only
     *    for the destination (`handled_elsewhere`), and unassigned "pending" ones
     *    stay in the handling branch's Ready to Assign queue only (the parcel is
     *    physically there, not at the destination).
     */
    private function whereBranch($query, array $branchIds): void
    {
        $query->where(function ($q) use ($branchIds) {
            $q->whereIn('delivery_assignments.branch_id', $branchIds)
                ->orWhereIn('delivery_assignments.sub_branch_id', $branchIds)
                ->orWhere(function ($d) use ($branchIds) {
                    $d->where('delivery_assignments.status', '!=', 'pending')
                        ->whereExists(function ($e) use ($branchIds) {
                            $e->selectRaw('1')->from('shipments as dest_s')
                                ->whereColumn('dest_s.id', 'delivery_assignments.shipment_id')
                                ->where(function ($w) use ($branchIds) {
                                    $w->whereIn('dest_s.destination_branch_id', $branchIds)
                                        ->orWhereIn('dest_s.destination_sub_branch_id', $branchIds);
                                });
                        });
                });
        });
    }

    /** Branch family the request is scoped to (null = HQ, every branch). */
    private function viewerFamily(Request $request): ?array
    {
        $user = $request->user();
        if ($this->isHq($user)) {
            $branchId = (int) $request->input('branch_id', 0);

            return $branchId > 0 ? $this->runs->branchFamily($branchId) : null;
        }
        $branchId = (int) ($user->branch_id ?? 0);
        if ($branchId > 0 && $this->isBranchOperator($user)) {
            return $this->runs->branchFamily($branchId);
        }

        return null;
    }

    /**
     * Flag rows another branch handles (shown to the final-destination branch
     * as view-only) with that branch's name.
     */
    private function attachHandlingInfo($rows, Request $request): void
    {
        $family = $this->viewerFamily($request);
        $ids = collect($rows)->flatMap(fn ($r) => [$r->branch_id, $r->sub_branch_id])->filter()->unique()->values()->all();
        $names = $ids ? \Illuminate\Support\Facades\DB::table('branches')->whereIn('id', $ids)->pluck('name', 'id') : collect();

        foreach ($rows as $row) {
            $handlerId = (int) ($row->sub_branch_id ?: $row->branch_id ?: 0);
            $row->setAttribute('handling_branch', $handlerId ? [
                'id' => $handlerId,
                'name' => $names[$handlerId] ?? $names[(int) $row->branch_id] ?? null,
            ] : null);
            $row->setAttribute('handled_elsewhere', $family !== null && ! $this->handles($row, $family));
        }
    }

    private function handles(DeliveryAssignment $delivery, array $family): bool
    {
        return in_array((int) $delivery->branch_id, $family, true)
            || in_array((int) $delivery->sub_branch_id, $family, true);
    }

    /**
     * Branch staff may only act (assign, fail) on deliveries their branch
     * handles; HQ may act on any. Rows another branch handles are view-only.
     */
    private function assertHandles(Request $request, DeliveryAssignment $delivery): void
    {
        $user = $request->user();
        if ($this->isHq($user)) {
            return;
        }
        $branchId = (int) ($user->branch_id ?? 0);
        if ($branchId <= 0) {
            return;
        }
        if (! $this->handles($delivery, $this->runs->branchFamily($branchId))) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'delivery' => ['This parcel is handled by another branch. It is view-only here.'],
            ]);
        }
    }

    private function isHq(User $user): bool
    {
        if ($user->isSuperAdmin() || in_array((string) $user->role, ['main_admin', 'admin'], true)) {
            return true;
        }
        try {
            return $user->hasAnyRole(['main_admin', 'admin']);
        } catch (\Throwable) {
            return false;
        }
    }

    private function isBranchOperator(User $user): bool
    {
        if (in_array((string) $user->role, ['branch_manager', 'sub_branch_manager'], true)) {
            return true;
        }
        try {
            return $user->hasAnyRole(['branch_manager', 'sub_branch_manager'])
                || $user->can('deliveries.assign');
        } catch (\Throwable) {
            return false;
        }
    }

    /** Type / search / merchant / marketplace / run filters shared by index and summary. */
    /** Correlated "this parcel is on an active delivery run" sub-query. */
    private function activeRunItem(): \Closure
    {
        return function ($e) {
            $e->selectRaw('1')->from('delivery_run_items as ri')
                ->join('delivery_runs as r', 'r.id', '=', 'ri.delivery_run_id')
                ->whereColumn('ri.shipment_id', 'delivery_assignments.shipment_id')
                ->where('ri.status', '!=', 'removed')
                ->whereIn('r.status', DeliveryRun::ACTIVE_STATUSES);
        };
    }

    /**
     * ?status= accepts a comma list of delivery statuses plus two board values:
     *  - ready:   pending and not on an active DR (Ready to Assign)
     *  - planned: pending and already on an active DR (DR created, rider set)
     */
    private function applyStatusFilter($query, Request $request): void
    {
        if (! $request->filled('status')) {
            return;
        }
        $statuses = array_values(array_unique(array_filter(array_map('trim', explode(',', $request->string('status')->toString())))));
        if (! $statuses) {
            return;
        }
        $plain = array_values(array_diff($statuses, ['ready', 'planned']));
        $ready = in_array('ready', $statuses, true) && ! in_array('pending', $plain, true);
        $planned = in_array('planned', $statuses, true) && ! in_array('pending', $plain, true);
        $onRun = $this->activeRunItem();

        $query->where(function ($w) use ($plain, $ready, $planned, $onRun) {
            if ($plain) {
                $w->orWhereIn('status', $plain);
            }
            if ($ready && $planned) {
                $w->orWhere('status', 'pending');
            } elseif ($ready) {
                $w->orWhere(fn ($q) => $q->where('status', 'pending')->whereNotExists($onRun));
            } elseif ($planned) {
                $w->orWhere(fn ($q) => $q->where('status', 'pending')->whereExists($onRun));
            }
        });
    }

    private function applyFilters($query, Request $request): void
    {
        if ($request->filled('delivery_type')) {
            $query->where('delivery_type', $request->string('delivery_type')->toString());
        }

        if ($request->filled('run_id')) {
            $runId = (int) $request->input('run_id');
            $query->whereExists(function ($e) use ($runId) {
                $e->selectRaw('1')->from('delivery_run_items as ri')
                    ->where('ri.delivery_run_id', $runId)
                    ->where('ri.status', '!=', 'removed')
                    ->where(function ($w) {
                        $w->whereColumn('ri.delivery_id', 'delivery_assignments.id')
                            ->orWhereColumn('ri.shipment_id', 'delivery_assignments.shipment_id');
                    });
            });
        }

        // on_run=1: only parcels on an active DR; on_run=0: only parcels not on one.
        if ($request->has('on_run') && $request->input('on_run') !== '' && $request->input('on_run') !== null) {
            $onRun = filter_var($request->input('on_run'), FILTER_VALIDATE_BOOLEAN);
            $exists = $this->activeRunItem();
            $onRun ? $query->whereExists($exists) : $query->whereNotExists($exists);
        }

        if ($request->filled('search')) {
            $search = trim($request->string('search')->toString());
            if ($search !== '') {
                $query->where(function ($w) use ($search) {
                    $w->whereHas('shipment', function ($q) use ($search) {
                        $q->where('tracking_number', 'like', "%{$search}%")
                            ->orWhere('receiver_name', 'like', "%{$search}%")
                            ->orWhere('receiver_phone', 'like', "%{$search}%")
                            ->orWhereHas('merchant', function ($mq) use ($search) {
                                $mq->where('name', 'like', "%{$search}%")
                                    ->orWhere('external_store_id', 'like', "%{$search}%")
                                    ->orWhere('code', 'like', "%{$search}%");
                            });
                    })->orWhereExists(function ($e) use ($search) {
                        // DR number, e.g. "DR-KTM-000011" or "000011".
                        $e->selectRaw('1')->from('delivery_run_items as ri')
                            ->join('delivery_runs as r', 'r.id', '=', 'ri.delivery_run_id')
                            ->whereColumn('ri.delivery_id', 'delivery_assignments.id')
                            ->where('ri.status', '!=', 'removed')
                            ->where('r.run_number', 'like', "%{$search}%");
                    });
                });
            }
        }

        if ($request->filled('merchant_id')) {
            $merchantId = (int) $request->input('merchant_id');
            $query->whereHas('shipment', fn ($q) => $q->where('merchant_id', $merchantId));
        }

        if ($request->filled('marketplace_id')) {
            $marketplaceId = (int) $request->input('marketplace_id');
            $query->whereHas('shipment', function ($q) use ($marketplaceId) {
                $q->where('marketplace_id', $marketplaceId)
                    ->orWhereHas('merchant', fn ($mq) => $mq->where('marketplace_id', $marketplaceId));
            });
        }
    }

    /**
     * Riders that can be assigned to a delivery's destination branch.
     */
    public function assignableRiders(Request $request, DeliveryAssignment $delivery)
    {
        $this->assertHandles($request, $delivery);

        $riders = $this->service->assignableRiders(
            $delivery->shipment,
            $delivery->branch_id ?? $delivery->sub_branch_id
        );

        return ApiResponse::success(
            $riders->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'phone' => $u->phone,
                'active_deliveries_count' => $u->active_deliveries_count ?? 0,
            ])->values()
        );
    }

    /**
     * Assign a rider to a delivery.
     */
    public function assign(Request $request, DeliveryAssignment $delivery, SettlementWorkflowService $settlements)
    {
        $data = $request->validate([
            'rider_id' => ['required', 'integer', 'exists:users,id'],
            'delivery_free_by' => ['sometimes', 'nullable', 'in:none,store,marketplace'],
            'run_mode' => ['sometimes', 'nullable', 'in:auto,run,individual'],
        ]);

        $this->assertHandles($request, $delivery);

        if ($delivery->shipment) {
            $settlements->applyFreeDelivery($delivery->shipment, $data, true);
        }

        $rider = User::query()->findOrFail($data['rider_id']);

        // Rider still collecting a run here: the parcel joins that run (bag + start) by default.
        $merged = $this->joinRidersRun([$delivery], $rider, $request, (string) ($data['run_mode'] ?? 'auto'));
        if ($merged && $merged['added'] !== []) {
            return ApiResponse::success($merged['payload'], $merged['message']);
        }

        return ApiResponse::success(
            $this->service->assign($delivery, $rider, $request->user()),
            'Rider assigned to delivery.'
        );
    }

    /**
     * Assign many pending deliveries to one rider (route hand-off).
     */
    public function bulkAssign(Request $request)
    {
        $data = $request->validate([
            'rider_id' => ['required', 'integer', 'exists:users,id'],
            'delivery_ids' => ['required', 'array', 'min:1'],
            'delivery_ids.*' => ['integer'],
            'run_mode' => ['sometimes', 'nullable', 'in:auto,run,individual'],
        ]);

        $rider = User::query()->findOrFail($data['rider_id']);

        // Branch staff can only bulk-assign parcels their branch handles.
        $foreign = [];
        $user = $request->user();
        if (! $this->isHq($user) && (int) ($user->branch_id ?? 0) > 0) {
            $family = $this->runs->branchFamily((int) $user->branch_id);
            $foreign = DeliveryAssignment::query()->whereIn('id', $data['delivery_ids'])->get()
                ->reject(fn (DeliveryAssignment $d) => $this->handles($d, $family))
                ->pluck('id')->map(fn ($v) => (int) $v)->all();
        }
        $ids = array_values(array_diff(array_map('intval', $data['delivery_ids']), $foreign));

        $runNote = '';
        $runPayload = null;
        if ($ids) {
            $merged = $this->joinRidersRun(
                DeliveryAssignment::query()->whereIn('id', $ids)->get()->all(),
                $rider,
                $request,
                (string) ($data['run_mode'] ?? 'auto')
            );
            if ($merged && $merged['added'] !== []) {
                $joined = DeliveryAssignment::query()->whereIn('id', $ids)->whereIn('shipment_id', $merged['added'])->pluck('id')->map(fn ($v) => (int) $v)->all();
                $ids = array_values(array_diff($ids, $joined));
                $runNote = $merged['message'];
                $runPayload = $merged['payload'];
            }
        }

        $result = $ids ? $this->service->bulkAssign($ids, $rider, $request->user()) : ['assigned' => [], 'skipped' => []];
        foreach ($foreign as $id) {
            $result['skipped'][$id] = 'Handled by another branch (view-only).';
        }
        if ($runPayload) {
            $result['run'] = $runPayload['run'];
            $result['joined_run'] = $runPayload['added'];
            if ($ids === []) {
                return ApiResponse::success($result, $runNote.($foreign ? ' '.count($foreign).' skipped (another branch).' : ''));
            }
        }

        $assignedCount = count($result['assigned']);
        $skippedCount = count($result['skipped']);

        return ApiResponse::success(
            $result,
            $skippedCount === 0
                ? "{$assignedCount} deliveries assigned to {$rider->name}.".($runNote ? ' '.$runNote : '')
                : "{$assignedCount} assigned, {$skippedCount} skipped.".($runNote ? ' '.$runNote : '')
        );
    }

    /**
     * When the rider has an un-started DR at this branch, put these deliveries'
     * parcels on it instead of assigning them one by one. Returns null when the
     * rider has no such run or individual assignment was asked for.
     *
     * @param  list<DeliveryAssignment>  $deliveries
     */
    private function joinRidersRun(array $deliveries, User $rider, Request $request, string $mode): ?array
    {
        if ($mode === 'individual' || $deliveries === []) {
            return null;
        }
        $first = $deliveries[0];
        $branchId = (int) ($first->branch_id ?? 0) ?: (int) ($first->sub_branch_id ?? 0);
        if ($branchId <= 0 && $first->shipment) {
            $branchId = (int) ($first->shipment->current_branch_id ?? $first->shipment->destination_branch_id ?? 0);
        }
        $run = $this->runs->unstartedRunFor((int) $rider->id, $branchId);
        if (! $run) {
            if ($mode === 'run') {
                throw \Illuminate\Validation\ValidationException::withMessages(['rider_id' => [$rider->name.' has no delivery run that is still collecting.']]);
            }

            return null;
        }
        $shipmentIds = array_values(array_unique(array_map(fn (DeliveryAssignment $d) => (int) $d->shipment_id, $deliveries)));
        $out = $this->runs->addItems($run, $shipmentIds, $request->user());
        $n = count($out['added']);
        $fresh = $out['run'];

        return [
            'added' => $out['added'],
            'payload' => [
                'run' => $this->runs->summarize($fresh),
                'added' => $out['added'],
                'skipped' => $out['skipped'],
                'off_route' => $out['off_route'],
                'merged' => true,
            ],
            'message' => $n
                ? "Added {$n} parcel".($n === 1 ? '' : 's')." to {$fresh->run_number} ({$rider->name}'s current run, not started; now {$fresh->expected_count} parcels). {$rider->name} must bag them before starting."
                    .($out['off_route'] ? ' Off-route: '.implode(', ', array_slice($out['off_route'], 0, 5)).'.' : '')
                : '',
        ];
    }

    public function failed(Request $request, DeliveryAssignment $delivery)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->assertHandles($request, $delivery);

        return ApiResponse::success(
            $this->service->failed($delivery, $request->user(), $data['reason']),
            'Delivery marked as failed.'
        );
    }
}
