<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\BranchShareCalculator;
use App\Support\FinanceBranchScope;
use Illuminate\Http\Request;
use Modules\Billing\Models\ShipmentBranchShare;
use Modules\Billing\Services\BranchCommissionService;
use Modules\Billing\Services\BranchShareService;
use Modules\Shipment\Models\Shipment;

class BranchShareController extends Controller
{
    public function __construct(private BranchShareService $shares)
    {
    }

    /**
     * GET admin/branch-shares
     * Default: one row per shipment with its per-branch split (paginated by shipment).
     * ?flat=1 keeps the old one-row-per-share response.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_if(FinanceBranchScope::isMerchant($user), 403);

        if (! $request->boolean('flat')) {
            return ApiResponse::success(app(\Modules\Billing\Services\InterBranchInsightService::class)->shipmentList($request, $user));
        }

        $q = ShipmentBranchShare::query()
            ->with(['branch:id,name,code', 'collectingBranch:id,name,code', 'shipment:id,tracking_number,merchant_id,status,delivered_at,delivery_charge_paid_by,delivery_free_by'])
            ->latest('id');

        FinanceBranchScope::scopeShares($q, $user);

        if ($request->filled('branch_id') && FinanceBranchScope::canSeeBranch($user, $request->branch_id)) {
            $q->where('branch_id', (int) $request->branch_id);
        }
        if ($request->filled('status')) {
            $q->where('status', $request->status);
        }
        if ($request->filled('role')) {
            $q->where('role', $request->role);
        }
        if ($request->filled('shipment_id')) {
            $q->where('shipment_id', (int) $request->shipment_id);
        }
        if ($request->filled('from') || $request->filled('to')) {
            $q->whereHas('shipment', function ($s) use ($request) {
                if ($request->filled('from')) {
                    $s->whereDate('delivered_at', '>=', $request->from);
                }
                if ($request->filled('to')) {
                    $s->whereDate('delivered_at', '<=', $request->to);
                }
            });
        }
        if ($request->filled('search')) {
            $term = '%'.$request->search.'%';
            $q->whereHas('shipment', fn ($s) => $s->where('tracking_number', 'like', $term));
        }

        $summary = (clone $q)->reorder()->toBase()->selectRaw(
            'COUNT(*) as row_count, COALESCE(SUM(allocation_amount),0) as allocation, COALESCE(SUM(hq_commission_amount),0) as hq, COALESCE(SUM(net_amount),0) as net'
        )->first();

        $pendingConfig = Shipment::query()->where('branch_share_status', 'pending_config');
        FinanceBranchScope::scopeShipments($pendingConfig, $user);

        return ApiResponse::success([
            'shares' => $q->paginate((int) $request->get('per_page', 50)),
            'summary' => [
                'rows' => (int) ($summary->row_count ?? 0),
                'allocation' => round((float) ($summary->allocation ?? 0), 2),
                'hq_commission' => round((float) ($summary->hq ?? 0), 2),
                'net' => round((float) ($summary->net ?? 0), 2),
                'pending_config_shipments' => $pendingConfig->count(),
            ],
        ]);
    }

    /** GET admin/shipments/{shipment}/branch-shares */
    public function shipment(Request $request, Shipment $shipment)
    {
        $user = $request->user();
        abort_if(FinanceBranchScope::isMerchant($user), 403);

        $q = ShipmentBranchShare::query()
            ->with(['branch:id,name,code', 'collectingBranch:id,name,code'])
            ->where('shipment_id', $shipment->id)
            ->orderBy('position');
        FinanceBranchScope::scopeShares($q, $user);
        $rows = $q->get();

        $data = [
            'shipment_id' => $shipment->id,
            'branch_share_status' => $shipment->branch_share_status,
            'branch_share_note' => $shipment->branch_share_note,
            'rows' => $rows,
            'hops' => app(\Modules\Dispatch\Services\ManifestHopService::class)->hopsForShipment((int) $shipment->id),
        ];

        // Not delivered yet: show what the split would be (HQ only, never saved).
        if ($rows->isEmpty() && FinanceBranchScope::isHq($user)) {
            $data['preview'] = $this->shares->preview($shipment);
        }

        return ApiResponse::success($data);
    }

    /** POST admin/shipments/{shipment}/branch-shares/recompute (HQ) */
    public function recompute(Request $request, Shipment $shipment)
    {
        abort_unless(FinanceBranchScope::isHq($request->user()), 403);

        app(BranchCommissionService::class)->autoEnsureForShipment($shipment);
        $shipment->refresh();

        return ApiResponse::success([
            'branch_share_status' => $shipment->branch_share_status,
            'branch_share_note' => $shipment->branch_share_note,
            'rows' => ShipmentBranchShare::query()->where('shipment_id', $shipment->id)->orderBy('position')->get(),
        ], 'Branch shares recomputed.');
    }

    /** GET admin/branch-shares/config */
    public function config(Request $request)
    {
        $user = $request->user();
        abort_if(FinanceBranchScope::isMerchant($user), 403);

        $branches = \Illuminate\Support\Facades\DB::table('branches')
            ->where(function ($q) {
                $q->where('status', 'active')
                    ->orWhere(function ($franchise) {
                        $franchise->where('status', 'approved')
                            ->where('account_invitation_status', 'account_configured');
                    });
            })
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'type']);

        return ApiResponse::success([
            'rules' => $this->shares->rules(),
            'rules_is_default' => $this->shares->rulesIsDefault(),
            'default_rules' => BranchShareCalculator::DEFAULT_RULES,
            'table' => $this->shares->table(),
            'is_default' => $this->shares->tableIsDefault(),
            'defaults' => BranchShareCalculator::DEFAULT_TABLE,
            'hq_percent' => app(BranchCommissionService::class)->commissionRatePercent(),
            'branches' => $branches,
            'key' => BranchShareService::TABLE_KEY,
            'rules_key' => BranchShareService::RULES_KEY,
        ]);
    }

    /** POST admin/branch-shares/config (HQ only) */
    public function saveConfig(Request $request)
    {
        abort_unless(FinanceBranchScope::isHq($request->user()), 403);

        $response = [];

        if ($request->has('rules') && is_array($request->rules)) {
            $response['rules'] = $this->shares->saveRules($request->rules);
        }

        if ($request->has('table') && is_array($request->table)) {
            $data = $request->validate([
                'table' => ['required', 'array', 'min:1'],
                'table.*' => ['array'],
                'table.*.*' => ['numeric', 'min:0', 'max:100'],
            ]);
            $response['table'] = $this->shares->saveTable($data['table']);
        }

        // Shipments that were waiting for a missing config can be split now.
        $retried = 0;
        Shipment::query()
            ->where('branch_share_status', 'pending_config')
            ->where('status', 'delivered')
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->each(function (Shipment $s) use (&$retried) {
                try {
                    app(BranchCommissionService::class)->autoEnsureForShipment($s);
                    $retried++;
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        $response['retried_pending_config'] = $retried;

        return ApiResponse::success($response, 'Branch share configuration saved successfully.');
    }

    /** POST admin/branch-shares/simulate */
    public function simulate(Request $request)
    {
        $user = $request->user();
        abort_if(FinanceBranchScope::isMerchant($user), 403);

        $data = $request->validate([
            'origin_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'destination_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'transit_branch_ids' => ['nullable', 'array'],
            'transit_branch_ids.*' => ['integer', 'exists:branches,id'],
            'weight_kg' => ['nullable', 'numeric', 'min:0.01'],
            'is_fragile' => ['nullable', 'boolean'],
            'service_type' => ['nullable', 'string', 'in:standard,express,same_day'],
            'delivery_distance_km' => ['nullable', 'numeric', 'min:0'],
            'product_price_pod' => ['nullable', 'numeric', 'min:0'],
            'transfer_hops' => ['nullable', 'array'],
            'transfer_hops.*.transfer_number' => ['nullable', 'string'],
            'transfer_hops.*.from_branch_id' => ['nullable', 'integer'],
            'transfer_hops.*.to_branch_id' => ['nullable', 'integer'],
            'transfer_hops.*.trip_transport_cost' => ['nullable', 'numeric', 'min:0'],
            'transfer_hops.*.total_shipments_on_tr' => ['nullable', 'integer', 'min:1'],
            'rules' => ['nullable', 'array'],
            'hq_commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'extra_distance_rate' => ['nullable', 'numeric', 'min:0'],
            'base_distance_km' => ['nullable', 'numeric', 'min:0'],
            'base_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'weight_rate' => ['nullable', 'numeric', 'min:0'],
        ]);

        $result = $this->shares->simulate($data);

        return ApiResponse::success($result, 'Simulation completed successfully.');
    }

    /** GET admin/branch-shares/reports: branch-wise aggregation & history report */
    public function reports(Request $request)
    {
        $user = $request->user();
        abort_if(FinanceBranchScope::isMerchant($user), 403);

        $from = $request->filled('from') ? $request->from : null;
        $to = $request->filled('to') ? $request->to : null;
        $branchId = $request->filled('branch_id') ? (int) $request->branch_id : null;

        $data = $this->shares->branchWiseReports($from, $to, $branchId, $user);

        return ApiResponse::success($data, 'Branch shares reports retrieved successfully.');
    }
}
