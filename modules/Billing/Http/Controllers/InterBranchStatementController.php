<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\FinanceBranchScope;
use Illuminate\Http\Request;
use Modules\Billing\Models\InterBranchStatement;
use Modules\Billing\Services\InterBranchInsightService;
use Modules\Billing\Services\InterBranchStatementService;
use Modules\Shipment\Models\Shipment;

class InterBranchStatementController extends Controller
{
    public function __construct(
        private InterBranchStatementService $statements,
        private InterBranchInsightService $insight,
    ) {
    }

    /**
     * GET admin/inter-branch-settlements/position?branch_id=&from=&to=
     * What the branch owes other branches and what they owe it, per counterparty.
     * Branch users always get their own branch (another branch_id is 403).
     */
    public function position(Request $request)
    {
        $user = $request->user();
        abort_if(FinanceBranchScope::isMerchant($user), 403);
        $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $branchId = $request->filled('branch_id') ? (int) $request->branch_id : null;
        if (FinanceBranchScope::applies($user)) {
            $ids = FinanceBranchScope::branchIds($user);
            abort_if($ids === [], 403, 'Your account is not linked to a branch.');
            if ($branchId === null) {
                $branchId = (int) ($user->branch_id ?: $ids[0]);
            }
            abort_unless(in_array($branchId, $ids, true), 403, 'You can only see your own branch.');
        }

        return ApiResponse::success($this->insight->branchPosition($branchId, $request->from, $request->to));
    }

    /** GET admin/inter-branch-settlements/shipments: one row per shipment with its split. */
    public function shipments(Request $request)
    {
        $user = $request->user();
        abort_if(FinanceBranchScope::isMerchant($user), 403);

        return ApiResponse::success($this->insight->shipmentList($request, $user));
    }

    /** GET admin/inter-branch-settlements/shipments/{shipment}: full settlement breakdown. */
    public function shipmentBreakdown(Request $request, Shipment $shipment)
    {
        $user = $request->user();
        abort_if(FinanceBranchScope::isMerchant($user), 403);
        abort_unless($this->insight->canView($user, $shipment), 404, 'Shipment not found.');

        return ApiResponse::success($this->insight->shipmentBreakdown($shipment, $user));
    }

    public function index(Request $request)
    {
        $user = $request->user();
        abort_if(FinanceBranchScope::isMerchant($user), 403);

        $q = InterBranchStatement::query()
            ->with(['fromBranch:id,name,code', 'toBranch:id,name,code'])
            ->latest('id');
        FinanceBranchScope::scopeStatements($q, $user);

        if ($request->filled('branch_id') && FinanceBranchScope::canSeeBranch($user, $request->branch_id)) {
            $b = (int) $request->branch_id;
            $q->where(fn ($w) => $w->where('from_branch_id', $b)->orWhere('to_branch_id', $b));
        }
        if ($request->filled('direction') && in_array($request->direction, ['payable', 'receivable'], true)) {
            $ids = FinanceBranchScope::applies($user)
                ? FinanceBranchScope::branchIds($user)
                : ($request->filled('branch_id') ? [(int) $request->branch_id] : null);
            if ($ids !== null) {
                $request->direction === 'payable'
                    ? $q->whereIn('from_branch_id', $ids)
                    : $q->whereIn('to_branch_id', $ids);
            }
        }
        if ($request->filled('counterparty_id')) {
            $cp = (int) $request->counterparty_id;
            $q->where(fn ($w) => $w->where('from_branch_id', $cp)->orWhere('to_branch_id', $cp));
        }
        if ($request->filled('search')) {
            $q->where('statement_number', 'like', '%'.trim((string) $request->search).'%');
        }
        if ($request->filled('from')) {
            $q->whereDate('period_end', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $q->whereDate('period_start', '<=', $request->to);
        }

        $totals = (clone $q)->reorder()->toBase()
            ->selectRaw('status, COUNT(*) as c, COALESCE(SUM(total_amount),0) as t')
            ->groupBy('status')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->status => ['count' => (int) $r->c, 'amount' => round((float) $r->t, 2)]]);

        // Status narrows the list only; status_totals above count every status (for the tabs).
        if ($request->filled('status')) {
            $statuses = array_values(array_filter(explode(',', (string) $request->status)));
            $q->whereIn('status', $statuses);
        }

        $page = $q->paginate((int) $request->get('per_page', 50));

        return ApiResponse::success(array_merge($page->toArray(), ['status_totals' => $totals]));
    }

    public function show(Request $request, InterBranchStatement $statement)
    {
        $this->authorizeStatement($request, $statement);

        $statement->load([
            'fromBranch:id,name,code',
            'toBranch:id,name,code',
            'lines.shipment:id,tracking_number,merchant_id,delivered_at,status,payment_type,delivery_charge_paid_by',
            'lines.share:id,shipment_id,branch_id,percent,fare_amount,allocation_amount,hq_commission_amount,net_amount,collection_mode,status',
        ]);

        // Route path and merchant per line, without N+1 queries.
        $shipmentIds = $statement->lines->pluck('shipment_id')->unique()->values();
        $paths = \Illuminate\Support\Facades\DB::table('shipment_branch_shares as s')
            ->join('branches as b', 'b.id', '=', 's.branch_id')
            ->whereIn('s.shipment_id', $shipmentIds)
            ->orderBy('s.position')
            ->get(['s.shipment_id', 'b.name', 's.role'])
            ->groupBy('shipment_id');
        $merchants = \Illuminate\Support\Facades\DB::table('merchants')
            ->whereIn('id', $statement->lines->pluck('shipment.merchant_id')->filter()->unique())
            ->pluck('name', 'id');

        $data = $statement->toArray();
        $data['lines'] = $statement->lines->map(function ($l) use ($paths, $merchants) {
            $row = $l->toArray();
            $row['route'] = ($paths->get($l->shipment_id) ?? collect())->pluck('name')->values();
            $row['merchant_name'] = $l->shipment?->merchant_id ? ($merchants[$l->shipment->merchant_id] ?? null) : null;
            $row['delivered_at'] = $l->shipment?->delivered_at ? $l->shipment->delivered_at->format('Y-m-d H:i:s') : null;

            return $row;
        })->values();

        return ApiResponse::success($data);
    }

    /** POST admin/inter-branch-settlements/generate {period?, from?, to?} */
    public function generate(Request $request)
    {
        $user = $request->user();
        abort_if(FinanceBranchScope::isMerchant($user), 403);

        $data = $request->validate([
            'period' => ['nullable', 'in:daily,weekly'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        [$start, $end] = $this->statements->periodBounds($data['period'] ?? 'weekly', $data['from'] ?? null, $data['to'] ?? null);

        // Branch managers can only generate statements their branch is part of.
        $only = FinanceBranchScope::applies($user) ? FinanceBranchScope::branchIds($user) : null;

        $result = $this->statements->generate($start, $end, $only, $user->id);

        return ApiResponse::success([
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'statements' => $result['statements'],
            'lines' => $result['lines'],
            'kept_by_collector' => $result['kept_by_collector'],
        ], "{$result['lines']} share line(s) added to ".count($result['statements']).' statement(s).');
    }

    public function issue(Request $request, InterBranchStatement $statement)
    {
        $this->authorizeStatement($request, $statement, 'from');

        return ApiResponse::success($this->statements->issue($statement, $request->user()->id), 'Statement issued.');
    }

    public function payHamroPay(Request $request, InterBranchStatement $statement)
    {
        $this->authorizeStatement($request, $statement, 'from');

        return ApiResponse::success($this->statements->payViaHamroPay($statement), 'HamroPay session created.');
    }

    public function markPaid(Request $request, InterBranchStatement $statement)
    {
        $this->authorizeStatement($request, $statement, 'from');
        $data = $request->validate(['payment_reference' => ['nullable', 'string', 'max:191']]);

        return ApiResponse::success(
            $this->statements->markPaid($statement, $request->user()->id, $data['payment_reference'] ?? null),
            'Statement marked paid.'
        );
    }

    public function markReceived(Request $request, InterBranchStatement $statement)
    {
        $this->authorizeStatement($request, $statement, 'to');

        return ApiResponse::success($this->statements->markReceived($statement, $request->user()->id), 'Payment marked received.');
    }

    public function sendEmail(Request $request, InterBranchStatement $statement)
    {
        $this->authorizeStatement($request, $statement);

        return ApiResponse::success($this->statements->email($statement), 'Statement emailed.');
    }

    /**
     * $side: null = either branch may act, 'from' = paying branch, 'to' = receiving branch.
     * HQ may act on any statement.
     */
    private function authorizeStatement(Request $request, InterBranchStatement $statement, ?string $side = null): void
    {
        $user = $request->user();
        abort_if(FinanceBranchScope::isMerchant($user), 403);

        if (! FinanceBranchScope::applies($user)) {
            return;
        }

        $ids = FinanceBranchScope::branchIds($user);
        $isFrom = in_array((int) $statement->from_branch_id, $ids, true);
        $isTo = in_array((int) $statement->to_branch_id, $ids, true);

        abort_unless($isFrom || $isTo, 404, 'Statement not found.');

        if ($side === 'from') {
            abort_unless($isFrom, 403, 'Only the paying branch can do this.');
        } elseif ($side === 'to') {
            abort_unless($isTo, 403, 'Only the receiving branch can do this.');
        }
    }
}
