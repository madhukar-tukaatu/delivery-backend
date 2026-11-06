<?php

namespace Modules\Report\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\FinanceBranchScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Branch\Models\Branch;
use Modules\POD\Models\PodRecord;
use Modules\Delivery\Models\DeliveryAssignment;
use Modules\Merchant\Models\Merchant;
use Modules\Settlement\Models\MerchantSettlement;
use Modules\Shipment\Models\Shipment;

class ReportController extends Controller
{
    /**
     * Helper method to scope queries based on the logged-in user's authority
     */
    private function applyBranchScope($query, $column = 'origin_branch_id')
    {
        $user = Auth::user();

        if (!$user) {
            return $query;
        }

        // Super Admin or Main Branch roles have global access
        if ($user->hasRole('super_admin') || $user->branch_type === 'main') {
            return $query;
        }

        // Branch Managers/Staff are strictly limited to their own assigned branch ID
        if ($user->branch_id) {
            return $query->where($column, $user->branch_id);
        }

        // Fallback: If no branch assigned, restrict data entirely for security
        return $query->whereRaw('1 = 0');
    }

    private function getCacheKey(string $prefix): string
    {
        $user = Auth::user();
        if (!$user) {
            return "{$prefix}_guest";
        }
        if ($user->hasRole('super_admin') || $user->branch_type === 'main') {
            return "{$prefix}_global";
        }
        return "{$prefix}_branch_" . ($user->branch_id ?? '0');
    }

    private function rememberReport(string $key, int $ttlSeconds, callable $callback)
    {
        if (request()->boolean('refresh')) {
            Cache::forget($key);
        }
        return Cache::remember($key, $ttlSeconds, $callback);
    }

    public function dashboard()
    {
        $cacheKey = $this->getCacheKey('report_dashboard');

        $data = $this->rememberReport($cacheKey, 60, function () {
            $shipmentQuery = $this->applyBranchScope(Shipment::query(), 'origin_branch_id');

            $stats = (clone $shipmentQuery)->selectRaw("
                COUNT(*) as total_shipments,
                COUNT(CASE WHEN status = 'delivered' THEN 1 END) as delivered,
                COUNT(CASE WHEN status IN ('in_transit', 'sorted_for_transfer') THEN 1 END) as in_transit,
                COUNT(CASE WHEN status = 'out_for_delivery' THEN 1 END) as out_for_delivery,
                COUNT(CASE WHEN status IN ('returned', 'return_initiated') THEN 1 END) as returned,
                COALESCE(SUM(delivery_charge), 0) as gross_revenue
            ")->first();

            $totalShipments = (int) ($stats->total_shipments ?? 0);
            $delivered = (int) ($stats->delivered ?? 0);
            $inTransit = (int) ($stats->in_transit ?? 0);
            $outForDelivery = (int) ($stats->out_for_delivery ?? 0);
            $returned = (int) ($stats->returned ?? 0);
            $grossRevenue = (float) ($stats->gross_revenue ?? 0.00);

            $totalCod = Schema::hasTable('pod_records') ? (float) PodRecord::sum('pod_amount') : 0.00;
            $totalTrips = Schema::hasTable('transport_trips') ? DB::table('transport_trips')->count() : 0;
            $activeVehicles = Schema::hasTable('transport_vehicles') ? DB::table('transport_vehicles')->where('status', 'available')->count() : 0;

            $recentStatus = (clone $shipmentQuery)
                ->selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->get();

            return [
                'metrics' => [
                    'total_shipments' => $totalShipments,
                    'delivered'       => $delivered,
                    'in_transit'      => $inTransit,
                    'out_for_delivery'=> $outForDelivery,
                    'returned'        => $returned,
                    'delivery_rate'   => $totalShipments > 0 ? round(($delivered / $totalShipments) * 100, 1) : 0,
                    'gross_revenue'   => $grossRevenue,
                    'total_cod'       => $totalCod,
                    'active_fleet'    => $activeVehicles,
                    'total_trips'     => $totalTrips,
                ],
                'recent_status' => $recentStatus,
            ];
        });

        return ApiResponse::success($data);
    }

    public function shipments()
    {
        $cacheKey = $this->getCacheKey('report_shipments');

        $data = $this->rememberReport($cacheKey, 60, function () {
            $shipmentQuery = $this->applyBranchScope(Shipment::query(), 'origin_branch_id');

            $counts = (clone $shipmentQuery)->selectRaw("
                COUNT(*) as total,
                COUNT(CASE WHEN status = 'delivered' THEN 1 END) as delivered,
                COUNT(CASE WHEN status = 'delivery_failed' THEN 1 END) as failed,
                COUNT(CASE WHEN status = 'returned' THEN 1 END) as returned,
                COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled
            ")->first();

            $byStatus = (clone $shipmentQuery)
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->get();

            $byMerchant = (clone $shipmentQuery)
                ->with('merchant:id,name')
                ->selectRaw('merchant_id, count(*) as total, sum(pod_amount) as pod_total')
                ->groupBy('merchant_id')
                ->orderByDesc('total')
                ->limit(25)
                ->get();

            $byBranch = (clone $shipmentQuery)
                ->with('originBranch:id,name')
                ->selectRaw('origin_branch_id, count(*) as total')
                ->groupBy('origin_branch_id')
                ->orderByDesc('total')
                ->limit(25)
                ->get();

            return [
                'total'       => (int) ($counts->total ?? 0),
                'delivered'   => (int) ($counts->delivered ?? 0),
                'failed'      => (int) ($counts->failed ?? 0),
                'returned'    => (int) ($counts->returned ?? 0),
                'cancelled'   => (int) ($counts->cancelled ?? 0),
                'by_status'   => $byStatus,
                'by_merchant' => $byMerchant,
                'by_branch'   => $byBranch,
            ];
        });

        return ApiResponse::success($data);
    }

    public function revenue()
    {
        $cacheKey = $this->getCacheKey('report_revenue');

        $data = $this->rememberReport($cacheKey, 60, function () {
            $shipmentQuery = $this->applyBranchScope(Shipment::query(), 'origin_branch_id');

            $revStats = (clone $shipmentQuery)->selectRaw("
                COALESCE(SUM(delivery_charge), 0) as delivery_charges,
                COALESCE(SUM(pod_charge), 0) as pod_charges,
                COALESCE(SUM(return_charge), 0) as return_charges
            ")->first();

            $deliveryCharges = (float) ($revStats->delivery_charges ?? 0.00);
            $podCharges = (float) ($revStats->pod_charges ?? 0.00);
            $returnCharges = (float) ($revStats->return_charges ?? 0.00);
            $totalCharges = $deliveryCharges + $podCharges + $returnCharges;

            // Memory-safe SQL native grouping instead of loading million rows into PHP RAM
            $monthly = (clone $shipmentQuery)
                ->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as month, SUM(delivery_charge + COALESCE(pod_charge, 0) + COALESCE(return_charge, 0)) as total, COUNT(*) as parcel_count')
                ->groupBy(DB::raw('DATE_FORMAT(created_at, "%Y-%m")'))
                ->orderBy('month', 'asc')
                ->get();

            return [
                'delivery_charges' => $deliveryCharges,
                'pod_charges'      => $podCharges,
                'return_charges'   => $returnCharges,
                'total_charges'    => $totalCharges,
                'monthly'          => $monthly,
            ];
        });

        return ApiResponse::success($data);
    }

    public function expenses()
    {
        $cacheKey = $this->getCacheKey('report_expenses');

        $data = $this->rememberReport($cacheKey, 60, function () {
            $user = Auth::user();

            // 1. Line-haul Transportation Freight Expenses
            $freightCost = 0.00;
            $monthlyFreight = [];
            if (Schema::hasTable('transport_trips')) {
                $tripQuery = DB::table('transport_trips');
                if ($user && !$user->hasRole('super_admin') && $user->branch_type !== 'main' && $user->branch_id) {
                    $tripQuery->where(function ($q) use ($user) {
                        $q->where('from_branch_id', $user->branch_id)->orWhere('to_branch_id', $user->branch_id);
                    });
                }
                $freightCost = (float) (clone $tripQuery)->sum('freight_cost');
                $monthlyFreight = (clone $tripQuery)
                    ->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as month, SUM(freight_cost) as total, COUNT(*) as trips_count')
                    ->groupBy(DB::raw('DATE_FORMAT(created_at, "%Y-%m")'))
                    ->orderBy('month', 'asc')
                    ->get();
            }

            // 2. Reverse Logistics / RTO Costs
            $shipmentQuery = $this->applyBranchScope(Shipment::query(), 'origin_branch_id');
            $returnCost = (float) (clone $shipmentQuery)->whereIn('status', ['returned', 'return_initiated'])->sum('return_charge');

            // 3. HQ Commission / Royalty Bills
            $hqCommissionCost = 0.00;
            if (Schema::hasTable('branch_commission_bills')) {
                $commQuery = DB::table('branch_commission_bills');
                if ($user && !$user->hasRole('super_admin') && $user->branch_type !== 'main' && $user->branch_id) {
                    $commQuery->where('branch_id', $user->branch_id);
                }
                $hqCommissionCost = (float) (clone $commQuery)->sum('commission_amount');
            }

            $totalExpenses = $freightCost + $returnCost + $hqCommissionCost;

            return [
                'total_expenses'       => $totalExpenses,
                'freight_expenses'     => $freightCost,
                'return_handling_cost' => $returnCost,
                'hq_royalty_cost'      => $hqCommissionCost,
                'monthly_freight'      => $monthlyFreight,
            ];
        });

        return ApiResponse::success($data);
    }

    public function profitLoss()
    {
        $cacheKey = $this->getCacheKey('report_profit_loss');

        $data = $this->rememberReport($cacheKey, 60, function () {
            $shipmentQuery = $this->applyBranchScope(Shipment::query(), 'origin_branch_id');

            $revStats = (clone $shipmentQuery)->selectRaw("
                COALESCE(SUM(delivery_charge), 0) as delivery_rev,
                COALESCE(SUM(return_charge), 0) as return_rev
            ")->first();

            $grossRevenue = (float) ($revStats->delivery_rev ?? 0) + (float) ($revStats->return_rev ?? 0);

            $freightExpenses = Schema::hasTable('transport_trips') ? (float) DB::table('transport_trips')->sum('freight_cost') : 0.00;
            $returnCost = (float) (clone $shipmentQuery)->whereIn('status', ['returned', 'return_initiated'])->sum('return_charge');
            $hqRoyalty = Schema::hasTable('branch_commission_bills') ? (float) DB::table('branch_commission_bills')->sum('commission_amount') : 0.00;

            $totalExpenses = $freightExpenses + $returnCost + $hqRoyalty;
            $netOperatingIncome = $grossRevenue - $totalExpenses;
            $profitMargin = $grossRevenue > 0 ? round(($netOperatingIncome / $grossRevenue) * 100, 2) : 0;

            // Monthly P&L Trend
            $monthlyRevenue = (clone $shipmentQuery)
                ->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as month, SUM(delivery_charge + COALESCE(return_charge, 0)) as revenue')
                ->groupBy(DB::raw('DATE_FORMAT(created_at, "%Y-%m")'))
                ->pluck('revenue', 'month');

            $monthlyExpenses = Schema::hasTable('transport_trips')
                ? DB::table('transport_trips')
                    ->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as month, SUM(freight_cost) as expense')
                    ->groupBy(DB::raw('DATE_FORMAT(created_at, "%Y-%m")'))
                    ->pluck('expense', 'month')
                : collect();

            $monthlyPnl = [];
            foreach ($monthlyRevenue as $month => $rev) {
                $exp = (float) ($monthlyExpenses[$month] ?? 0) + round($rev * 0.15, 2); // Freight + Royalty
                $net = $rev - $exp;
                $monthlyPnl[] = [
                    'month'    => $month,
                    'revenue'  => round($rev, 2),
                    'expenses' => round($exp, 2),
                    'net'      => round($net, 2),
                    'margin'   => $rev > 0 ? round(($net / $rev) * 100, 1) : 0,
                ];
            }

            return [
                'gross_revenue'         => $grossRevenue,
                'total_expenses'        => $totalExpenses,
                'net_operating_income'  => $netOperatingIncome,
                'operating_margin_pct'  => $profitMargin,
                'monthly_pnl'           => $monthlyPnl,
            ];
        });

        return ApiResponse::success($data);
    }

    public function branchShares()
    {
        if (!Schema::hasTable('shipment_branch_shares')) {
            return ApiResponse::success(['total_origin_share' => 0, 'total_destination_share' => 0, 'total_hq_share' => 0, 'top_branches' => []]);
        }

        $cacheKey = $this->getCacheKey('report_branch_shares');

        $data = $this->rememberReport($cacheKey, 60, function () {
            $user = Auth::user();
            $query = DB::table('shipment_branch_shares');

            if ($user && !$user->hasRole('super_admin') && $user->branch_type !== 'main' && $user->branch_id) {
                $query->where('branch_id', $user->branch_id);
            }

            $originShare = (float) (clone $query)->where('role', 'origin')->sum('share_amount');
            $destShare = (float) (clone $query)->where('role', 'delivery')->sum('share_amount');
            $hqShare = (float) (clone $query)->where('role', 'origin')->sum('hq_commission_amount');

            $topOrigin = (clone $query)
                ->join('branches', 'branches.id', '=', 'shipment_branch_shares.branch_id')
                ->where('shipment_branch_shares.role', 'origin')
                ->select('branches.name as branch_name', DB::raw('SUM(share_amount) as total_origin_earned'), DB::raw('COUNT(*) as total_shipments'))
                ->groupBy('branches.id', 'branches.name')
                ->orderByDesc('total_origin_earned')
                ->limit(10)
                ->get();

            $topDest = (clone $query)
                ->join('branches', 'branches.id', '=', 'shipment_branch_shares.branch_id')
                ->where('shipment_branch_shares.role', 'delivery')
                ->select('branches.name as branch_name', DB::raw('SUM(share_amount) as total_dest_earned'), DB::raw('COUNT(*) as total_deliveries'))
                ->groupBy('branches.id', 'branches.name')
                ->orderByDesc('total_dest_earned')
                ->limit(10)
                ->get();

            return [
                'origin_share_total'      => $originShare,
                'destination_share_total' => $destShare,
                'hq_share_total'          => $hqShare,
                'split_ratios'            => ['origin' => 40, 'destination' => 45, 'hq' => 15],
                'top_origin_branches'     => $topOrigin,
                'top_dest_branches'       => $topDest,
            ];
        });

        return ApiResponse::success($data);
    }

    public function cashFlow()
    {
        $cacheKey = $this->getCacheKey('report_cash_flow');

        $data = $this->rememberReport($cacheKey, 60, function () {
            $totalCollected = Schema::hasTable('pod_collections') ? (float) DB::table('pod_collections')->sum('total_collected') : 0.00;
            $riderDeposits = Schema::hasTable('rider_cash_deposits') ? (float) DB::table('rider_cash_deposits')->where('status', 'confirmed')->sum('amount') : 0.00;
            $bankDeposits = Schema::hasTable('pod_deposits') ? (float) DB::table('pod_deposits')->where('status', 'approved')->sum('amount') : 0.00;
            $merchantPayouts = Schema::hasTable('merchant_settlements') ? (float) DB::table('merchant_settlements')->where('status', 'completed')->sum('final_payable_amount') : 0.00;

            $vaultBalance = max(0, $riderDeposits - $bankDeposits);

            return [
                'doorstep_collected'   => $totalCollected,
                'rider_shift_deposits' => $riderDeposits,
                'branch_bank_deposits' => $bankDeposits,
                'merchant_payouts'     => $merchantPayouts,
                'vault_cash_in_hand'   => $vaultBalance,
            ];
        });

        return ApiResponse::success($data);
    }

    public function transactions(Request $request)
    {
        $perPage = $request->integer('per_page', 20);

        // Fetch recent settlement vouchers
        $settlements = Schema::hasTable('merchant_settlements')
            ? DB::table('merchant_settlements')
                ->join('merchants', 'merchants.id', '=', 'merchant_settlements.merchant_id')
                ->select(
                    'merchant_settlements.settlement_number as reference',
                    DB::raw("'Merchant Settlement Payout' as type"),
                    'merchants.name as party',
                    'merchant_settlements.final_payable_amount as amount',
                    'merchant_settlements.payment_method as method',
                    'merchant_settlements.status as status',
                    'merchant_settlements.settled_at as transaction_date'
                )
                ->latest('merchant_settlements.id')
                ->limit($perPage)
                ->get()
            : collect();

        // Fetch recent rider shift deposits
        $deposits = Schema::hasTable('rider_cash_deposits')
            ? DB::table('rider_cash_deposits')
                ->join('users', 'users.id', '=', 'rider_cash_deposits.rider_id')
                ->select(
                    'rider_cash_deposits.deposit_number as reference',
                    DB::raw("'Rider Cash Handover' as type"),
                    'users.name as party',
                    'rider_cash_deposits.amount as amount',
                    DB::raw("'cash' as method"),
                    'rider_cash_deposits.status as status',
                    'rider_cash_deposits.created_at as transaction_date'
                )
                ->latest('rider_cash_deposits.id')
                ->limit($perPage)
                ->get()
            : collect();

        return ApiResponse::success([
            'recent_settlements' => $settlements,
            'recent_deposits'    => $deposits,
        ]);
    }

    public function pod()
    {
        $cacheKey = $this->getCacheKey('report_pod');

        $data = $this->rememberReport($cacheKey, 60, function () {
            $user = Auth::user();
            $codQuery = PodRecord::query();

            if ($user && !$user->hasRole('super_admin') && $user->branch_type !== 'main' && $user->branch_id) {
                $codQuery->whereHas('shipment', function ($q) use ($user) {
                    $q->where('origin_branch_id', $user->branch_id)
                      ->orWhere('destination_branch_id', $user->branch_id);
                });
            }

            $sums = (clone $codQuery)->selectRaw("
                COALESCE(SUM(pod_amount), 0) as total_cod,
                COALESCE(SUM(CASE WHEN status IN ('collected','deposited','settled','verified') THEN collected_amount ELSE 0 END), 0) as collected,
                COALESCE(SUM(CASE WHEN status = 'pending' THEN pod_amount ELSE 0 END), 0) as pending,
                COALESCE(SUM(CASE WHEN status = 'settled' THEN collected_amount ELSE 0 END), 0) as settled
            ")->first();

            $byStatus = (clone $codQuery)
                ->selectRaw('status, count(*) as total, sum(pod_amount) as amount')
                ->groupBy('status')
                ->get();

            return [
                'total_cod' => (float) ($sums->total_cod ?? 0.00),
                'collected' => (float) ($sums->collected ?? 0.00),
                'pending'   => (float) ($sums->pending ?? 0.00),
                'settled'   => (float) ($sums->settled ?? 0.00),
                'by_status' => $byStatus,
            ];
        });

        return ApiResponse::success($data);
    }

    public function merchants()
    {
        $cacheKey = $this->getCacheKey('report_merchants');

        $data = $this->rememberReport($cacheKey, 60, function () {
            $user = Auth::user();
            $shipmentQuery = $this->applyBranchScope(Shipment::query(), 'origin_branch_id');

            $merchantCounts = Merchant::selectRaw("
                COUNT(*) as total,
                COUNT(CASE WHEN status IN ('approved', 'active') THEN 1 END) as active,
                COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
                COUNT(CASE WHEN status = 'suspended' THEN 1 END) as suspended
            ")->first();

            $shipmentCounts = (clone $shipmentQuery)->with('merchant:id,name')
                ->selectRaw('merchant_id, count(*) as total, sum(pod_amount) as pod_total')
                ->groupBy('merchant_id')
                ->orderByDesc('total')
                ->limit(30)
                ->get();

            $settlements = FinanceBranchScope::scopeSettlements(MerchantSettlement::query(), $user)
                ->selectRaw('status, count(*) as total, sum(final_payable_amount) as amount')
                ->groupBy('status')
                ->get();

            return [
                'total'           => (int) ($merchantCounts->total ?? 0),
                'active'          => (int) ($merchantCounts->active ?? 0),
                'pending'         => (int) ($merchantCounts->pending ?? 0),
                'suspended'       => (int) ($merchantCounts->suspended ?? 0),
                'shipment_counts' => $shipmentCounts,
                'settlements'     => $settlements,
            ];
        });

        return ApiResponse::success($data);
    }

    public function branches()
    {
        $cacheKey = $this->getCacheKey('report_branches');

        $data = $this->rememberReport($cacheKey, 60, function () {
            $user = Auth::user();

            if ($user && !$user->hasRole('super_admin') && $user->branch_type !== 'main' && $user->branch_id) {
                return [
                    'total' => 1,
                    'by_type' => Branch::where('id', $user->branch_id)->selectRaw('type, count(*) as total')->groupBy('type')->get(),
                    'shipments_by_origin' => Shipment::where('origin_branch_id', $user->branch_id)->with('originBranch:id,name')->selectRaw('origin_branch_id, count(*) as total')->groupBy('origin_branch_id')->get(),
                    'shipments_by_destination' => Shipment::where('destination_branch_id', $user->branch_id)->with('destinationBranch:id,name')->selectRaw('destination_branch_id, count(*) as total')->groupBy('destination_branch_id')->get(),
                ];
            }

            return [
                'total' => Branch::count(),
                'by_type' => Branch::selectRaw('type, count(*) as total')->groupBy('type')->get(),
                'shipments_by_origin' => Shipment::with('originBranch:id,name')->selectRaw('origin_branch_id, count(*) as total')->groupBy('origin_branch_id')->orderByDesc('total')->limit(20)->get(),
                'shipments_by_destination' => Shipment::with('destinationBranch:id,name')->selectRaw('destination_branch_id, count(*) as total')->groupBy('destination_branch_id')->orderByDesc('total')->limit(20)->get(),
            ];
        });

        return ApiResponse::success($data);
    }

    public function staff()
    {
        $cacheKey = $this->getCacheKey('report_staff');

        $data = $this->rememberReport($cacheKey, 60, function () {
            $user = Auth::user();
            $userQuery = User::query();
            $assignmentQuery = DeliveryAssignment::query();

            if ($user && !$user->hasRole('super_admin') && $user->branch_type !== 'main' && $user->branch_id) {
                $userQuery->where('branch_id', $user->branch_id);
                $assignmentQuery->whereHas('staff', function ($q) use ($user) {
                    $q->where('branch_id', $user->branch_id);
                });
            }

            return [
                'users_by_role' => $userQuery->selectRaw('role, count(*) as total')->groupBy('role')->get(),
                'delivery_assignments' => $assignmentQuery->with('staff:id,name')->selectRaw('delivery_staff_id, count(*) as total')->groupBy('delivery_staff_id')->orderByDesc('total')->limit(20)->get(),
            ];
        });

        return ApiResponse::success($data);
    }
}