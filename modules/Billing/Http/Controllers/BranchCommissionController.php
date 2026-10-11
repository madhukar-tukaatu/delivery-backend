<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\FinanceBranchScope;
use Illuminate\Http\Request;
use Modules\Billing\Models\BranchCommissionBill;
use Modules\Billing\Models\BranchCommissionSettlement;
use Modules\Billing\Services\BranchCommissionService;

class BranchCommissionController extends Controller
{
    public function __construct(private BranchCommissionService $commissions)
    {
    }

    public function settings(Request $request)
    {
        // Branch users only read their own branch rate; another branch_id is ignored.
        $branchId = $request->filled('branch_id') && FinanceBranchScope::canSeeBranch($request->user(), $request->branch_id)
            ? (int) $request->branch_id
            : null;

        return ApiResponse::success([
            'hq_percent' => $this->commissions->commissionRatePercent(),
            'branch_percent' => $branchId !== null
                ? $this->commissions->commissionRatePercent($branchId)
                : null,
        ]);
    }

    public function updateSettings(Request $request)
    {
        $user = $request->user();
        abort_unless(
            ($user->isSuperAdmin() ?? false) || $user->hasRole(['super_admin', 'main_admin']),
            403
        );

        $data = $request->validate([
            'hq_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $this->commissions->setCommissionRatePercent(
            (float) $data['hq_percent'],
            isset($data['branch_id']) ? (int) $data['branch_id'] : null,
        );

        return ApiResponse::success([
            'hq_percent' => $this->commissions->commissionRatePercent(
                isset($data['branch_id']) ? (int) $data['branch_id'] : null
            ),
        ], 'Commission rate saved.');
    }

    public function bills(Request $request)
    {
        $q = BranchCommissionBill::query()->with(['branch', 'shipment'])->latest('id');

        $user = $request->user();
        // Branch users: own branch only (no branch => nothing). Other-branch branch_id is ignored.
        FinanceBranchScope::scopeBranchColumn($q, $user);

        if ($request->filled('branch_id') && FinanceBranchScope::canSeeBranch($user, $request->branch_id)) {
            $q->where('branch_id', (int) $request->branch_id);
        }
        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }

        return ApiResponse::success($q->paginate((int) $request->get('per_page', 50)));
    }

    public function settlements(Request $request)
    {
        $q = BranchCommissionSettlement::query()->with(['bills', 'branch:id,name,code'])->latest('id');

        $user = $request->user();
        FinanceBranchScope::scopeBranchColumn($q, $user);

        if ($request->filled('branch_id') && FinanceBranchScope::canSeeBranch($user, $request->branch_id)) {
            $q->where('branch_id', (int) $request->branch_id);
        }
        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }

        return ApiResponse::success($q->paginate((int) $request->get('per_page', 50)));
    }

    public function createSettlement(Request $request)
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'bill_ids' => ['nullable', 'array'],
            'bill_ids.*' => ['integer'],
            'adjustments' => ['nullable', 'numeric'],
        ]);

        $user = $request->user();
        abort_unless(FinanceBranchScope::canSeeBranch($user, $data['branch_id']), 403);

        $settlement = $this->commissions->createSettlement(
            (int) $data['branch_id'],
            $data['bill_ids'] ?? [],
            (float) ($data['adjustments'] ?? 0),
        );

        return ApiResponse::success($settlement, 'HQ commission settlement created.', 201);
    }

    public function payHamroPay(Request $request, BranchCommissionSettlement $settlement)
    {
        abort_unless(FinanceBranchScope::canSeeBranch($request->user(), $settlement->branch_id), 404, 'Settlement not found.');

        $session = $this->commissions->payViaHamroPay($settlement);

        return ApiResponse::success($session, 'HamroPay session created for HQ commission payment.');
    }

    public function markPaid(Request $request, BranchCommissionSettlement $settlement)
    {
        abort_unless(FinanceBranchScope::canSeeBranch($request->user(), $settlement->branch_id), 404, 'Settlement not found.');

        $data = $request->validate([
            'payment_reference' => ['nullable', 'string', 'max:191'],
        ]);

        $updated = $this->commissions->markPaid(
            $settlement,
            $request->user()->id,
            $data['payment_reference'] ?? null,
        );

        return ApiResponse::success($updated, 'Marked paid to Tukaatu Express.');
    }

    public function summary(Request $request)
    {
        $base = BranchCommissionBill::query();
        $user = $request->user();
        FinanceBranchScope::scopeBranchColumn($base, $user);

        $branchFilter = null;
        if ($request->filled('branch_id') && FinanceBranchScope::canSeeBranch($user, $request->branch_id)) {
            $branchFilter = (int) $request->branch_id;
            $base->where('branch_id', $branchFilter);
        }
        // Branch users see their own branch rate on the summary.
        $rateBranch = $branchFilter
            ?? (FinanceBranchScope::applies($user) && $user->branch_id ? (int) $user->branch_id : null);

        $monthStart = now()->startOfMonth();

        // HQ: per-branch breakdown (owed / processing / paid / this month).
        $byBranch = [];
        if (! FinanceBranchScope::applies($user)) {
            $rows = (clone $base)
                ->selectRaw("branch_id,
                    COALESCE(SUM(CASE WHEN status = 'unpaid' THEN commission_amount ELSE 0 END),0) unpaid_amount,
                    SUM(CASE WHEN status = 'unpaid' THEN 1 ELSE 0 END) unpaid_count,
                    COALESCE(SUM(CASE WHEN status = 'processing' THEN commission_amount ELSE 0 END),0) processing_amount,
                    COALESCE(SUM(CASE WHEN status = 'paid' THEN commission_amount ELSE 0 END),0) paid_amount,
                    COALESCE(SUM(CASE WHEN status = 'paid' AND paid_at >= ? THEN commission_amount ELSE 0 END),0) paid_this_month_amount,
                    COALESCE(SUM(commission_amount),0) total_amount", [$monthStart])
                ->groupBy('branch_id')
                ->get();
            $names = \Modules\Branch\Models\Branch::query()->whereIn('id', $rows->pluck('branch_id')->filter())->get(['id', 'name', 'code'])->keyBy('id');
            $byBranch = $rows->map(fn ($r) => [
                'branch_id' => $r->branch_id ? (int) $r->branch_id : null,
                'branch_name' => $names->get((int) $r->branch_id)?->name ?? ('Branch #'.$r->branch_id),
                'branch_code' => $names->get((int) $r->branch_id)?->code,
                'unpaid_amount' => round((float) $r->unpaid_amount, 2),
                'unpaid_count' => (int) $r->unpaid_count,
                'processing_amount' => round((float) $r->processing_amount, 2),
                'paid_amount' => round((float) $r->paid_amount, 2),
                'paid_this_month_amount' => round((float) $r->paid_this_month_amount, 2),
                'total_amount' => round((float) $r->total_amount, 2),
                'hq_percent' => $r->branch_id ? $this->commissions->commissionRatePercent((int) $r->branch_id) : null,
            ])->sortByDesc('unpaid_amount')->values()->all();
        }

        return ApiResponse::success([
            'unpaid_amount' => (float) (clone $base)->where('status', 'unpaid')->sum('commission_amount'),
            'processing_amount' => (float) (clone $base)->where('status', 'processing')->sum('commission_amount'),
            'paid_amount' => (float) (clone $base)->where('status', 'paid')->sum('commission_amount'),
            'paid_this_month_amount' => (float) (clone $base)->where('status', 'paid')->where('paid_at', '>=', $monthStart)->sum('commission_amount'),
            'this_month_amount' => (float) (clone $base)->where('created_at', '>=', $monthStart)->sum('commission_amount'),
            'unpaid_count' => (clone $base)->where('status', 'unpaid')->count(),
            'processing_count' => (clone $base)->where('status', 'processing')->count(),
            'hq_percent' => $this->commissions->commissionRatePercent($rateBranch),
            'is_hq' => ! FinanceBranchScope::applies($user),
            'by_branch' => $byBranch,
        ]);
    }
}
