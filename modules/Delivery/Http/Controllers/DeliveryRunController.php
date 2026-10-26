<?php

namespace Modules\Delivery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Delivery\Models\DeliveryRun;
use Modules\Delivery\Services\DeliveryRunService;

/**
 * DR delivery runs: admin / branch-manager board and the rider's run view.
 * Route names map to the existing deliveries.* permissions (see routes/api.php).
 */
class DeliveryRunController extends Controller
{
    public function __construct(
        private readonly DeliveryRunService $runs,
    ) {}

    /* ---------------------------- admin ---------------------------- */

    /** GET admin/deliveries/runs : DR cards for the branch. */
    public function index(Request $request)
    {
        $scope = $this->scope($request);
        $q = DeliveryRun::query()->with(['branch:id,name,code', 'rider:id,name,phone']);
        if ($scope > 0) {
            $q->whereIn('branch_id', $this->runs->branchFamily($scope));
        }

        $status = trim((string) $request->input('status', ''));
        if ($status === 'active') {
            $q->whereIn('status', ['open', 'assigned', 'out_for_delivery', 'completed']);
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
                        $e->selectRaw('1')->from('delivery_run_items as ri')
                            ->join('shipments as s', 's.id', '=', 'ri.shipment_id')
                            ->whereColumn('ri.delivery_run_id', 'delivery_runs.id')
                            ->where('s.tracking_number', 'like', "%{$term}%");
                    });
            });
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $page = $q->orderByRaw("FIELD(status, 'completed', 'out_for_delivery', 'assigned', 'open', 'closed', 'cancelled')")
            ->latest('id')->paginate($perPage);
        $page->setCollection($page->getCollection()->map(fn (DeliveryRun $r) => $this->runs->summarize($r)));

        return ApiResponse::success($page);
    }

    /** GET admin/deliveries/runs/stats : counts for the board header. */
    public function stats(Request $request)
    {
        $scope = $this->scope($request);
        $base = DeliveryRun::query()->when($scope > 0, fn ($q) => $q->whereIn('branch_id', $this->runs->branchFamily($scope)));
        $byStatus = (clone $base)->selectRaw('status, COUNT(*) c')->groupBy('status')->pluck('c', 'status');
        $today = (clone $base)->whereDate('created_at', today());

        return ApiResponse::success([
            'by_status' => $byStatus,
            'ready' => $this->runs->readyQuery($scope)->count(),
            'today' => [
                'runs' => (clone $today)->count(),
                'delivered' => (int) (clone $today)->sum('delivered_count'),
                'failed' => (int) (clone $today)->sum('failed_count'),
                'cod_collected' => round((float) (clone $today)->sum('cod_collected'), 2),
            ],
            'cash_with_riders' => round((float) (clone $base)->whereIn('status', ['out_for_delivery', 'completed'])->sum('cod_collected'), 2),
        ]);
    }

    /** GET admin/deliveries/runs/ready : parcels sorted for delivery and not on a run. */
    public function ready(Request $request)
    {
        $scope = $this->scope($request);

        return ApiResponse::success($this->runs->readyList($scope, $request->input('search')));
    }

    /** GET admin/deliveries/runs/riders : riders of the branch for the run picker. */
    public function riders(Request $request)
    {
        $scope = $this->scope($request);
        if ($request->filled('run_id')) {
            $run = DeliveryRun::query()->find((int) $request->input('run_id'));
            if ($run) {
                $this->assertScope($run, $scope);
                $scope = (int) $run->branch_id;
            }
        }

        return ApiResponse::success($this->runs->riders($scope));
    }

    /** GET admin/deliveries/runs/suggest?shipment_ids[]=1 : un-started runs these parcels could join. */
    public function suggest(Request $request)
    {
        $data = $request->validate([
            'shipment_ids' => ['nullable', 'array', 'max:300'],
            'shipment_ids.*' => ['integer'],
        ]);

        return ApiResponse::success($this->runs->suggestRuns($data['shipment_ids'] ?? [], $this->scope($request)));
    }

    /** GET admin/deliveries/runs/lookup?code=DR-KATHM-000004 */
    public function lookup(Request $request)
    {
        $request->validate(['code' => ['required', 'string', 'max:60']]);
        $run = $this->runs->findByNumber((string) $request->input('code'), $this->scope($request));
        abort_unless($run, 404, 'No delivery run with that number at your branch.');

        return ApiResponse::success($this->runs->summarize($run, true));
    }

    public function show(Request $request, DeliveryRun $run)
    {
        $this->assertScope($run, $this->scope($request));

        return ApiResponse::success($this->runs->summarize($run, true));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'shipment_ids' => ['required', 'array', 'min:1', 'max:300'],
            'shipment_ids.*' => ['integer'],
            'branch_id' => ['nullable', 'integer'],
            'rider_user_id' => ['nullable', 'integer'],
            'vehicle_type' => ['nullable', 'string', Rule::in(DeliveryRun::VEHICLE_TYPES)],
            'vehicle_number' => ['nullable', 'string', 'max:60'],
            'area_label' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'auto_sequence' => ['nullable', 'boolean'],
            'dispatch_now' => ['nullable', 'boolean'],
            'run_mode' => ['nullable', 'string', Rule::in(['auto', 'existing', 'new'])],
        ]);

        $out = $this->runs->create($data['shipment_ids'], $this->scope($request), $data, $request->user());
        $run = $out['run'];
        if (! empty($out['merged'])) {
            $added = count($out['added']);
            $skippedN = count($out['skipped']);
            $riderName = $run->rider?->name ?? 'The rider';
            $msg = "Added {$added} parcel".($added === 1 ? '' : 's')." to {$run->run_number} ({$riderName}'s current run, not started; now {$run->expected_count} parcels)."
                .($skippedN ? " {$skippedN} skipped." : '')
                .($added ? " {$riderName} must bag them before starting." : '')
                .($out['off_route'] ? ' Off-route: '.implode(', ', array_slice($out['off_route'], 0, 5)).'.' : '');

            return ApiResponse::success([
                'run' => $this->runs->summarize($run, true), 'skipped' => $out['skipped'], 'added' => $out['added'],
                'merged' => true, 'off_route' => $out['off_route'],
            ], $msg);
        }
        // dispatch_now is ignored: the rider collects, bags every parcel and starts the run.
        $skipped = count($out['skipped']);
        $rider = $run->rider_user_id ? ($run->rider?->name ?? 'The rider') : null;

        return ApiResponse::success(
            ['run' => $this->runs->summarize($run, true), 'skipped' => $out['skipped']],
            "{$run->run_number} created with {$run->expected_count} parcel".($run->expected_count === 1 ? '' : 's').($skipped ? ", {$skipped} skipped" : '').'.'
                .($rider ? " {$rider} will collect and bag them, then start the run." : ' Assign a rider to start collecting.')
        );
    }

    public function addItems(Request $request, DeliveryRun $run)
    {
        $this->assertScope($run, $this->scope($request));
        $data = $request->validate([
            'shipment_ids' => ['required', 'array', 'min:1', 'max:300'],
            'shipment_ids.*' => ['integer'],
        ]);
        $out = $this->runs->addItems($run, $data['shipment_ids'], $request->user());

        return ApiResponse::success(
            ['run' => $this->runs->summarize($out['run'], true), 'added' => $out['added'], 'skipped' => $out['skipped']],
            count($out['added']).' added to '.$out['run']->run_number.''.($out['skipped'] ? ', '.count($out['skipped']).' skipped' : '').'.'
                .($out['added'] && $out['run']->status === DeliveryRun::STATUS_ASSIGNED ? ' The rider must bag them before starting.' : '')
                .($out['off_route'] ? ' Off-route: '.implode(', ', array_slice($out['off_route'], 0, 5)).'.' : '')
        );
    }

    public function removeItems(Request $request, DeliveryRun $run)
    {
        $this->assertScope($run, $this->scope($request));
        $data = $request->validate([
            'shipment_ids' => ['required', 'array', 'min:1'],
            'shipment_ids.*' => ['integer'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $out = $this->runs->removeItems($run, $data['shipment_ids'], $data['reason'] ?? null, $request->user());

        return ApiResponse::success(
            ['run' => $this->runs->summarize($out['run'], true), 'removed' => $out['removed']],
            count($out['removed']).' removed.'
        );
    }

    public function assignRider(Request $request, DeliveryRun $run)
    {
        $this->assertScope($run, $this->scope($request));
        $data = $request->validate([
            'rider_user_id' => ['required', 'integer'],
            'vehicle_type' => ['nullable', 'string', Rule::in(DeliveryRun::VEHICLE_TYPES)],
            'vehicle_number' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'allow_second_run' => ['nullable', 'boolean'],
        ]);
        $run = $this->runs->assignRider($run, (int) $data['rider_user_id'], $data, $request->user());

        return ApiResponse::success($this->runs->summarize($run, true), "{$run->run_number} assigned to ".($run->rider?->name ?? 'rider').'.');
    }

    public function sequence(Request $request, DeliveryRun $run)
    {
        $this->assertScope($run, $this->scope($request));
        $data = $request->validate([
            'item_ids' => ['nullable', 'array'],
            'item_ids.*' => ['integer'],
        ]);
        $run = $this->runs->sequence($run, $data['item_ids'] ?? null);

        return ApiResponse::success($this->runs->summarize($run, true), 'Stop order saved.');
    }

    public function dispatch(Request $request, DeliveryRun $run)
    {
        $this->assertScope($run, $this->scope($request));
        $data = $request->validate([
            'rider_user_id' => ['nullable', 'integer'],
            'vehicle_type' => ['nullable', 'string', Rule::in(DeliveryRun::VEHICLE_TYPES)],
            'vehicle_number' => ['nullable', 'string', 'max:60'],
        ]);
        $user = $request->user();
        abort_unless($user->isSuperAdmin() || $user->hasRole('main_admin'), 403, 'The rider starts the run after bagging every parcel.');
        $run = $this->runs->dispatch($run, $user, $data);

        return ApiResponse::success($this->runs->summarize($run, true), "{$run->run_number} started by admin override. ".$this->outCount($run).'.');
    }

    public function close(Request $request, DeliveryRun $run)
    {
        $this->assertScope($run, $this->scope($request));
        $data = $request->validate([
            'cash_received' => ['nullable', 'numeric', 'min:0'],
            'deposit_cash' => ['nullable', 'boolean'],
            'default_action' => ['nullable', 'in:reattempt,return'],
            'items' => ['nullable', 'array'],
            'items.*.item_id' => ['required', 'integer'],
            'items.*.action' => ['required', 'in:reattempt,return'],
            'items.*.reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        if (array_key_exists('deposit_cash', $data) && $data['deposit_cash'] === null) {
            unset($data['deposit_cash']);
        }
        $run = $this->runs->close($run, $data, $request->user());

        return ApiResponse::success($this->runs->summarize($run, true), "{$run->run_number} closed.");
    }

    public function cancel(Request $request, DeliveryRun $run)
    {
        $this->assertScope($run, $this->scope($request));
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $run = $this->runs->cancel($run, $data['reason'] ?? null, $request->user());

        return ApiResponse::success($this->runs->summarize($run, true), "{$run->run_number} cancelled.");
    }

    /* ---------------------------- rider ---------------------------- */

    /** GET staff/deliveries/runs : the rider's active runs (+ last few closed). */
    public function staffIndex(Request $request)
    {
        $user = $request->user();
        $active = DeliveryRun::query()->where('rider_user_id', $user->id)
            ->whereIn('status', ['assigned', 'out_for_delivery', 'completed'])
            ->orderByRaw("FIELD(status, 'out_for_delivery', 'assigned', 'completed')")
            ->latest('id')->limit(10)->get();
        $recent = DeliveryRun::query()->where('rider_user_id', $user->id)
            ->where('status', 'closed')->latest('closed_at')->limit(5)->get();

        return ApiResponse::success([
            'active' => $active->map(fn (DeliveryRun $r) => $this->runs->summarize($r, true))->values(),
            'recent' => $recent->map(fn (DeliveryRun $r) => $this->runs->summarize($r))->values(),
        ]);
    }

    public function staffShow(Request $request, DeliveryRun $run)
    {
        abort_unless((int) $run->rider_user_id === (int) $request->user()->id, 404);

        return ApiResponse::success($this->runs->summarize($run, true));
    }

    /** POST staff/deliveries/runs/{run}/bag : {item_id} or {code} (scanned / typed tracking no.). */
    public function staffBag(Request $request, DeliveryRun $run)
    {
        $data = $request->validate([
            'item_id' => ['nullable', 'integer', 'required_without:code'],
            'code' => ['nullable', 'string', 'max:80', 'required_without:item_id'],
        ]);
        $out = $this->runs->bag($run, $request->user(), isset($data['item_id']) ? (int) $data['item_id'] : null, $data['code'] ?? null);
        $summary = $this->runs->summarize($out['run'], true);
        $progress = "Bagged {$summary['bagged_count']}/".($summary['bagged_count'] + $summary['to_bag_count']);

        return ApiResponse::success(
            ['run' => $summary, 'item_id' => $out['item']->id],
            $out['already'] ? "{$out['tracking']} is already bagged. {$progress}." : "{$out['tracking']} bagged. {$progress}."
        );
    }

    /** POST staff/deliveries/runs/{run}/unbag : {item_id} */
    public function staffUnbag(Request $request, DeliveryRun $run)
    {
        $data = $request->validate(['item_id' => ['required', 'integer']]);
        $out = $this->runs->unbag($run, $request->user(), (int) $data['item_id']);

        return ApiResponse::success(['run' => $this->runs->summarize($out['run'], true)], "{$out['tracking']} taken out of the bag.");
    }

    /** POST staff/deliveries/runs/{run}/missing : {item_id, note?} parcel not found at the warehouse. */
    public function staffMissing(Request $request, DeliveryRun $run)
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        $out = $this->runs->reportMissing($run, $request->user(), (int) $data['item_id'], $data['note'] ?? null);

        return ApiResponse::success(
            ['run' => $this->runs->summarize($out['run'], true)],
            "{$out['tracking']} removed from {$out['run']->run_number} and sent back to the branch queue. The branch has been told."
        );
    }

    /** POST staff/deliveries/runs/{run}/start : every parcel bagged, rider leaves. */
    public function staffStart(Request $request, DeliveryRun $run)
    {
        $run = $this->runs->startByRider($run, $request->user());

        return ApiResponse::success($this->runs->summarize($run, true), "{$run->run_number} started. ".$this->outCount($run).'. Deliver in the listed order.');
    }

    /** "3 deliveries accepted and out for delivery" for the start/dispatch toast. */
    private function outCount(DeliveryRun $run): string
    {
        $n = \Modules\Delivery\Models\DeliveryRunItem::query()->where('delivery_run_id', $run->id)
            ->where('status', \Modules\Delivery\Models\DeliveryRunItem::STATUS_OUT)->count();

        return $n.' parcel'.($n === 1 ? '' : 's').' out for delivery';
    }

    /** POST staff/deliveries/runs/{run}/finish : rider finished every stop and heads back. */
    public function staffFinish(Request $request, DeliveryRun $run)
    {
        $run = $this->runs->finishByRider($run, $request->user());

        return ApiResponse::success($this->runs->summarize($run, true), "{$run->run_number} finished. Hand over cash and failed parcels at the branch.");
    }

    /* --------------------------- helpers --------------------------- */

    /** 0 = HQ (all branches, or ?branch_id=); otherwise the user's branch. */
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

    private function assertScope(DeliveryRun $run, int $scope): void
    {
        if ($scope > 0 && ! in_array((int) $run->branch_id, $this->runs->branchFamily($scope), true)) {
            abort(404, 'Delivery run not found at your branch.');
        }
    }
}
