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

    /** GET admin/branch-shares */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_if(FinanceBranchScope::isMerchant($user), 403);

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
        abort_if(FinanceBranchScope::isMerchant($request->user()), 403);

        return ApiResponse::success([
            'table' => $this->shares->table(),
            'is_default' => $this->shares->tableIsDefault(),
            'defaults' => BranchShareCalculator::DEFAULT_TABLE,
            'hq_percent' => app(BranchCommissionService::class)->commissionRatePercent(),
            'key' => BranchShareService::TABLE_KEY,
        ]);
    }

    /** POST admin/branch-shares/config (HQ only) body: {table: {"1":[100],"2":[60,40],...}} */
    public function saveConfig(Request $request)
    {
        abort_unless(FinanceBranchScope::isHq($request->user()), 403);

        $data = $request->validate([
            'table' => ['required', 'array', 'min:1'],
            'table.*' => ['array'],
            'table.*.*' => ['numeric', 'min:0', 'max:100'],
        ]);

        $saved = $this->shares->saveTable($data['table']);

        // Shipments that were waiting for a missing row can be split now.
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

        return ApiResponse::success(['table' => $saved, 'retried_pending_config' => $retried], 'Branch share table saved.');
    }
}
