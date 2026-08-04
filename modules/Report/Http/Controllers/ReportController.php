<?php

namespace Modules\Report\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Branch\Models\Branch;
use Modules\POD\Models\CodRecord;
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

    public function shipments()
    {
        // 1. Build Scoped Query builders
        $shipmentQuery = $this->applyBranchScope(Shipment::query(), 'origin_branch_id');
        
        // Clone queries for accurate dashboard statistics
        return ApiResponse::success([
            'total'     => (clone $shipmentQuery)->count(),
            'delivered' => (clone $shipmentQuery)->where('status', 'delivered')->count(),
            'failed'    => (clone $shipmentQuery)->where('status', 'delivery_failed')->count(),
            'returned'  => (clone $shipmentQuery)->where('status', 'returned')->count(),
            'cancelled' => (clone $shipmentQuery)->where('status', 'cancelled')->count(),
            
            'by_status'   => (clone $shipmentQuery)->selectRaw('status, count(*) as total')->groupBy('status')->get(),
            'by_merchant' => (clone $shipmentQuery)->with('merchant:id,name')
                                ->selectRaw('merchant_id, count(*) as total')
                                ->groupBy('merchant_id')->get(),
            'by_branch'   => (clone $shipmentQuery)->with('originBranch:id,name')
                                ->selectRaw('origin_branch_id, count(*) as total')
                                ->groupBy('origin_branch_id')->get(),
        ]);
    }

    public function revenue()
    {
        $shipmentQuery = $this->applyBranchScope(Shipment::query(), 'origin_branch_id');

        return ApiResponse::success([
            'delivery_charges' => (clone $shipmentQuery)->sum('delivery_charge'),
            'pod_charges'      => (clone $shipmentQuery)->sum('pod_charge'),
            'return_charges'   => (clone $shipmentQuery)->sum('return_charge'),
            'total_charges'    => (clone $shipmentQuery)->sum('delivery_charge') + (clone $shipmentQuery)->sum('pod_charge') + (clone $shipmentQuery)->sum('return_charge'),
            'monthly'          => (clone $shipmentQuery)->get()->groupBy(fn ($s) => $s->created_at->format('Y-m'))->map(fn ($rows, $month) => [
                'month' => $month, 
                'total' => $rows->sum(fn ($s) => (float) $s->delivery_charge + (float) $s->pod_charge + (float) $s->return_charge)
            ])->values(),
        ]);
    }

    public function pod()
    {
        // POD Records mapped by matching scoped shipment networks
        $user = Auth::user();
        $codQuery = CodRecord::query();

        if (!$user->hasRole('super_admin') && $user->branch_type !== 'main') {
            $codQuery->whereHas('shipment', function ($q) use ($user) {
                $q->where('origin_branch_id', $user->branch_id)
                  ->orWhere('destination_branch_id', $user->branch_id);
            });
        }

        return ApiResponse::success([
            'total_cod' => (clone $codQuery)->sum('pod_amount'),
            'collected' => (clone $codQuery)->whereIn('status', ['collected','deposited','settled'])->sum('collected_amount'),
            'pending'   => (clone $codQuery)->where('status', 'pending')->sum('pod_amount'),
            'settled'   => (clone $codQuery)->where('status', 'settled')->sum('collected_amount'),
            'by_status' => (clone $codQuery)->selectRaw('status, count(*) as total, sum(pod_amount) as amount')->groupBy('status')->get(),
        ]);
    }

    public function merchants()
    {
        $user = Auth::user();
        
        // Local branches only see merchants who have interaction logs inside their specific branch scope
        $shipmentQuery = $this->applyBranchScope(Shipment::query(), 'origin_branch_id');

        return ApiResponse::success([
            'total'           => Merchant::count(), // Keeps track of global records or scope via region configuration if needed
            'active'          => Merchant::where('status', 'active')->count(),
            'pending'         => Merchant::where('status', 'pending')->count(),
            'suspended'       => Merchant::where('status', 'suspended')->count(),
            'shipment_counts' => (clone $shipmentQuery)->with('merchant:id,name')
                                    ->selectRaw('merchant_id, count(*) as total, sum(pod_amount) as pod_total')
                                    ->groupBy('merchant_id')->get(),
            'settlements'     => MerchantSettlement::selectRaw('status, count(*) as total, sum(final_payable_amount) as amount')->groupBy('status')->get(),
        ]);
    }

    public function branches()
    {
        $user = Auth::user();
        
        // If a branch manager views this, they only see stats relevant to their node identity
        if (!$user->hasRole('super_admin') && $user->branch_type !== 'main') {
            return ApiResponse::success([
                'total' => 1,
                'by_type' => Branch::where('id', $user->branch_id)->selectRaw('type, count(*) as total')->groupBy('type')->get(),
                'shipments_by_origin' => Shipment::where('origin_branch_id', $user->branch_id)->with('originBranch:id,name')->selectRaw('origin_branch_id, count(*) as total')->groupBy('origin_branch_id')->get(),
                'shipments_by_destination' => Shipment::where('destination_branch_id', $user->branch_id)->with('destinationBranch:id,name')->selectRaw('destination_branch_id, count(*) as total')->groupBy('destination_branch_id')->get(),
            ]);
        }

        return ApiResponse::success([
            'total' => Branch::count(),
            'by_type' => Branch::selectRaw('type, count(*) as total')->groupBy('type')->get(),
            'shipments_by_origin' => Shipment::with('originBranch:id,name')->selectRaw('origin_branch_id, count(*) as total')->groupBy('origin_branch_id')->get(),
            'shipments_by_destination' => Shipment::with('destinationBranch:id,name')->selectRaw('destination_branch_id, count(*) as total')->groupBy('destination_branch_id')->get(),
        ]);
    }

    public function staff()
    {
        $user = Auth::user();
        $userQuery = User::query();
        $assignmentQuery = DeliveryAssignment::query();

        if (!$user->hasRole('super_admin') && $user->branch_type !== 'main') {
            $userQuery->where('branch_id', $user->branch_id);
            $assignmentQuery->whereHas('staff', function($q) use ($user) {
                $q->where('branch_id', $user->branch_id);
            });
        }

        return ApiResponse::success([
            'users_by_role' => $userQuery->selectRaw('role, count(*) as total')->groupBy('role')->get(),
            'delivery_assignments' => $assignmentQuery->with('staff:id,name')->selectRaw('delivery_staff_id, count(*) as total')->groupBy('delivery_staff_id')->get(),
        ]);
    }
}