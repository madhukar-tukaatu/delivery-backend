<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\FinanceBranchScope;
use Illuminate\Http\Request;
use Modules\Billing\Models\InterBranchStatement;
use Modules\Billing\Services\InterBranchStatementService;

class InterBranchStatementController extends Controller
{
    public function __construct(private InterBranchStatementService $statements)
    {
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
        if ($request->filled('direction') && FinanceBranchScope::applies($user)) {
            $ids = FinanceBranchScope::branchIds($user);
            $request->direction === 'payable'
                ? $q->whereIn('from_branch_id', $ids)
                : $q->whereIn('to_branch_id', $ids);
        }
        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }
        if ($request->filled('from')) {
            $q->whereDate('period_end', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $q->whereDate('period_start', '<=', $request->to);
        }

        return ApiResponse::success($q->paginate((int) $request->get('per_page', 50)));
    }

    public function show(Request $request, InterBranchStatement $statement)
    {
        $this->authorizeStatement($request, $statement);

        return ApiResponse::success($statement->load([
            'fromBranch:id,name,code',
            'toBranch:id,name,code',
            'lines.shipment:id,tracking_number,merchant_id,delivered_at',
        ]));
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
