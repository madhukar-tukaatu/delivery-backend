<?php

namespace Modules\Pickup\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Pickup\Models\PickupRun;
use Modules\Pickup\Services\PickupRunService;

class PickupRunController extends Controller
{
    public function __construct(
        private readonly PickupRunService $runs,
    ) {}

    /* ---------------------------- admin ---------------------------- */

    /** GET admin/pickups/runs : PR cards for the branch. */
    public function index(Request $request)
    {
        $scope = $this->scope($request);
        $q = PickupRun::query()->with(['branch:id,name,code,latitude,longitude', 'rider:id,name,phone']);
        if ($scope > 0) {
            $q->whereIn('branch_id', $this->runs->branchFamily($scope));
        }

        $status = trim((string) $request->input('status', ''));
        if ($status === 'active') {
            $q->whereIn('status', ['open', 'assigned', 'in_progress', 'completed']);
        } elseif ($status !== '' && $status !== 'all') {
            $q->whereIn('status', array_filter(explode(',', $status)));
        }
        if ($request->filled('rider_id')) {
            $q->where('rider_user_id', (int) $request->input('rider_id'));
        }
        if ($request->filled('date_from')) {
            $q->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $q->whereDate('created_at', '<=', $request->input('date_to'));
        }
        if ($request->filled('search')) {
            $term = trim((string) $request->input('search'));
            $q->where(function ($w) use ($term) {
                $w->where('run_number', 'like', "%{$term}%")
                    ->orWhereHas('rider', fn ($r) => $r->where('name', 'like', "%{$term}%"))
                    ->orWhereExists(function ($e) use ($term) {
                        $e->selectRaw('1')->from('pickup_run_items as pri')
                            ->join('pickup_requests as pr', 'pr.id', '=', 'pri.pickup_request_id')
                            ->whereColumn('pri.pickup_run_id', 'pickup_runs.id')
                            ->where('pr.request_number', 'like', "%{$term}%");
                    });
            });
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $page = $q->orderByRaw("FIELD(status, 'completed', 'in_progress', 'assigned', 'open', 'closed', 'cancelled')")
            ->latest('id')->paginate($perPage);
        $page->setCollection($page->getCollection()->map(fn (PickupRun $r) => $this->runs->summarize($r)));

        return ApiResponse::success($page);
    }

    /** GET admin/pickups/runs/stats : counts for the header. */
    public function stats(Request $request)
    {
        $scope = $this->scope($request);
        $base = PickupRun::query()->when($scope > 0, fn ($q) => $q->whereIn('branch_id', $this->runs->branchFamily($scope)));
        $byStatus = (clone $base)->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');
        $today = (clone $base)->whereDate('created_at', today());

        return ApiResponse::success([
            'by_status' => $byStatus,
            'ready' => $this->runs->readyQuery($scope)->count(),
            'today' => [
                'runs' => (clone $today)->count(),
                'picked_up' => (int) (clone $today)->sum('picked_up_count'),
                'failed' => (int) (clone $today)->sum('failed_count'),
            ],
        ]);
    }

    /** GET admin/pickups/runs/ready : pickups ready to be added to a run. */
    public function ready(Request $request)
    {
        $scope = $this->scope($request);

        return ApiResponse::success($this->runs->readyList($scope, $request->input('search')));
    }

    /** GET admin/pickups/runs/riders : riders of the branch. */
    public function riders(Request $request)
    {
        $scope = $this->scope($request);
        if ($request->filled('run_id')) {
            $run = PickupRun::query()->find((int) $request->input('run_id'));
            if ($run) {
                $this->assertScope($run, $scope);
                $scope = (int) $run->branch_id;
            }
        }

        return ApiResponse::success($this->runs->riders($scope));
    }

    /** GET admin/pickups/runs/lookup?code=PR-KATHM-000001 */
    public function lookup(Request $request)
    {
        $request->validate(['code' => ['required', 'string', 'max:60']]);
        $run = $this->runs->findByNumber((string) $request->input('code'), $this->scope($request));
        abort_unless($run, 404, 'No pickup run with that number at your branch.');

        return ApiResponse::success($this->runs->summarize($run, true));
    }

    public function show(Request $request, PickupRun $run)
    {
        $this->assertScope($run, $this->scope($request));

        return ApiResponse::success($this->runs->summarize($run, true));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'pickup_request_ids' => ['required', 'array', 'min:1', 'max:300'],
            'pickup_request_ids.*' => ['integer'],
            'branch_id' => ['nullable', 'integer'],
            'rider_user_id' => ['nullable', 'integer'],
            'vehicle_type' => ['nullable', 'string', Rule::in(PickupRun::VEHICLE_TYPES)],
            'vehicle_number' => ['nullable', 'string', 'max:60'],
            'area_label' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'auto_sequence' => ['nullable', 'boolean'],
            'dispatch_now' => ['nullable', 'boolean'],
        ]);

        $out = $this->runs->create($data['pickup_request_ids'], $this->scope($request), $data, $request->user());
        $run = $out['run'];
        $dispatchError = null;
        if (! empty($data['dispatch_now'])) {
            // The run is already saved: a dispatch failure must not look like a failed create.
            try {
                $run = $this->runs->dispatch($run, $request->user());
            } catch (\Illuminate\Validation\ValidationException $e) {
                $dispatchError = (string) (collect($e->errors())->flatten()->first() ?: $e->getMessage());
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
                $dispatchError = $e->getMessage() ?: 'Not allowed.';
            } catch (\Throwable $e) {
                report($e);
                $dispatchError = 'Dispatch failed. Try Dispatch again from the run.';
            }
            if ($dispatchError !== null) {
                $run = $run->fresh();
            }
        }
        $skipped = count($out['skipped']);
        $msg = "{$run->run_number} created with {$run->expected_count} stop".($run->expected_count === 1 ? '' : 's').($skipped ? ", {$skipped} skipped" : '').'.';
        if ($dispatchError !== null) {
            $msg = "{$run->run_number} created but not dispatched: {$dispatchError}";
        }

        return ApiResponse::success(
            ['run' => $this->runs->summarize($run, true), 'skipped' => $out['skipped'], 'dispatch_error' => $dispatchError],
            $msg
        );
    }

    public function addItems(Request $request, PickupRun $run)
    {
        $this->assertScope($run, $this->scope($request));
        $data = $request->validate([
            'pickup_request_ids' => ['required', 'array', 'min:1', 'max:300'],
            'pickup_request_ids.*' => ['integer'],
        ]);
        $out = $this->runs->addItems($run, $data['pickup_request_ids'], $request->user());

        return ApiResponse::success(
            ['run' => $this->runs->summarize($out['run'], true), 'added' => $out['added'], 'skipped' => $out['skipped']],
            count($out['added']).' added'.($out['skipped'] ? ', '.count($out['skipped']).' skipped' : '').'.'
        );
    }

    public function removeItems(Request $request, PickupRun $run)
    {
        $this->assertScope($run, $this->scope($request));
        $data = $request->validate([
            'pickup_request_ids' => ['required', 'array', 'min:1'],
            'pickup_request_ids.*' => ['integer'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $out = $this->runs->removeItems($run, $data['pickup_request_ids'], $data['reason'] ?? null, $request->user());

        return ApiResponse::success(
            ['run' => $this->runs->summarize($out['run'], true), 'removed' => $out['removed']],
            count($out['removed']).' removed.'
        );
    }

    public function assignRider(Request $request, PickupRun $run)
    {
        $this->assertScope($run, $this->scope($request));
        $data = $request->validate([
            'rider_user_id' => ['required', 'integer'],
            'vehicle_type' => ['nullable', 'string', Rule::in(PickupRun::VEHICLE_TYPES)],
            'vehicle_number' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $run = $this->runs->assignRider($run, (int) $data['rider_user_id'], $data, $request->user());

        return ApiResponse::success($this->runs->summarize($run, true), "{$run->run_number} assigned to ".($run->rider?->name ?? 'rider').'.');
    }

    public function sequence(Request $request, PickupRun $run)
    {
        $this->assertScope($run, $this->scope($request));
        $data = $request->validate([
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => ['integer'],
        ]);
        $run = $this->runs->sequence($run, $data['item_ids'] ?? null);

        return ApiResponse::success($this->runs->summarize($run, true), 'Pickup stop order saved.');
    }

    public function dispatch(Request $request, PickupRun $run)
    {
        $this->assertScope($run, $this->scope($request));
        $data = $request->validate([
            'rider_user_id' => ['nullable', 'integer'],
            'vehicle_type' => ['nullable', 'string', Rule::in(PickupRun::VEHICLE_TYPES)],
            'vehicle_number' => ['nullable', 'string', 'max:60'],
        ]);
        $run = $this->runs->dispatch($run, $request->user(), $data);
        $results = $this->runs->lastStartResults;

        return ApiResponse::success(
            $this->runs->summarize($run, true) + ['start_results' => $results],
            "{$run->run_number} is in progress. ".$this->runs->startSummary($results).'.'
        );
    }

    public function close(Request $request, PickupRun $run)
    {
        $this->assertScope($run, $this->scope($request));
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $run = $this->runs->close($run, $data, $request->user());

        return ApiResponse::success($this->runs->summarize($run, true), "{$run->run_number} closed.");
    }

    public function cancel(Request $request, PickupRun $run)
    {
        $this->assertScope($run, $this->scope($request));
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $run = $this->runs->cancel($run, $data['reason'] ?? null, $request->user());

        return ApiResponse::success($this->runs->summarize($run, true), "{$run->run_number} cancelled.");
    }

    /** POST admin/pickups/runs/{run}/receive-shipment : receive a single shipment within the run */
    public function receiveShipment(Request $request, PickupRun $run)
    {
        $this->assertScope($run, $this->scope($request));
        $data = $request->validate([
            'pickup_request_id' => ['required', 'integer'],
            'shipment_id' => ['required', 'integer'],
            'actual_weight' => ['nullable', 'numeric'],
            'length' => ['nullable', 'numeric'],
            'width' => ['nullable', 'numeric'],
            'height' => ['nullable', 'numeric'],
        ]);

        $res = $this->runs->receiveRunShipment(
            $run,
            (int) $data['pickup_request_id'],
            (int) $data['shipment_id'],
            $request->user(),
            $data
        );

        return ApiResponse::success($res, 'Shipment received at branch successfully.');
    }

    /** POST admin/pickups/runs/{run}/receive-all : receive all collected shipments in the run */
    public function receiveAll(Request $request, PickupRun $run)
    {
        $this->assertScope($run, $this->scope($request));

        $res = $this->runs->receiveAllRunShipments($run, $request->user());

        $msg = "{$res['received_count']} shipment(s) received successfully.";
        if (! empty($res['errors'])) {
            $msg .= ' Some shipments could not be received.';
        }

        return ApiResponse::success($res, $msg);
    }

    /* ---------------------------- rider / staff ---------------------------- */

    /** GET staff/pickups/runs : the rider's active runs (+ last few closed). */
    public function staffIndex(Request $request)
    {
        $user = $request->user();
        // Repair runs that were started before run-start advanced their pickups.
        try {
            $this->runs->syncLaggingRuns((int) $user->id);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('pickup_run.sync_on_open_failed', ['rider_id' => $user->id, 'error' => $e->getMessage()]);
        }
        $active = PickupRun::query()->where('rider_user_id', $user->id)
            ->whereIn('status', ['assigned', 'in_progress', 'completed'])
            ->orderByRaw("FIELD(status, 'in_progress', 'assigned', 'completed')")
            ->latest('id')->limit(10)->get();
        $recent = PickupRun::query()->where('rider_user_id', $user->id)
            ->where('status', 'closed')->latest('closed_at')->limit(5)->get();

        return ApiResponse::success([
            'active' => $active->map(fn (PickupRun $r) => $this->runs->summarize($r, true))->values(),
            'recent' => $recent->map(fn (PickupRun $r) => $this->runs->summarize($r))->values(),
        ]);
    }

    public function staffShow(Request $request, PickupRun $run)
    {
        abort_unless((int) $run->rider_user_id === (int) $request->user()->id, 404);
        try {
            $this->runs->syncLaggingRuns((int) $request->user()->id, (int) $run->id);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('pickup_run.sync_on_open_failed', ['run_id' => $run->id, 'error' => $e->getMessage()]);
        }

        return ApiResponse::success($this->runs->summarize($run->fresh(), true));
    }

    /** POST staff/pickups/runs/{run}/start : rider begins their pickup run. */
    public function staffStart(Request $request, PickupRun $run)
    {
        $run = $this->runs->startByRider($run, $request->user());
        $results = $this->runs->lastStartResults;

        return ApiResponse::success(
            $this->runs->summarize($run, true) + ['start_results' => $results],
            "{$run->run_number} started. ".$this->runs->startSummary($results).'. Proceed to pickup stops in order.'
        );
    }

    /** POST staff/pickups/runs/{run}/finish : rider finished every stop and heads back. */
    public function staffFinish(Request $request, PickupRun $run)
    {
        $run = $this->runs->finishByRider($run, $request->user());

        return ApiResponse::success($this->runs->summarize($run, true), "{$run->run_number} finished. Hand over the collected parcels at the branch.");
    }

    /* --------------------------- helpers --------------------------- */

    private function scope(Request $request): int
    {
        $user = $request->user();
        if ($user->isSuperAdmin() || $user->hasRole('main_admin') || $user->hasRole('admin')) {
            return max(0, (int) $request->input('branch_id', 0));
        }
        $branchId = (int) ($user->branch_id ?? 0);
        abort_if($branchId <= 0, 403, 'Your account is not linked to a branch.');

        return $branchId;
    }

    private function assertScope(PickupRun $run, int $scope): void
    {
        if ($scope > 0 && ! in_array((int) $run->branch_id, $this->runs->branchFamily($scope), true)) {
            abort(404, 'Pickup run not found at your branch.');
        }
    }
}

