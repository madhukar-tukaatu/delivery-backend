<?php

namespace Modules\Delivery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\CourierStatus;
use Illuminate\Http\Request;
use Modules\Delivery\Models\DeliveryAssignment;
use Modules\Delivery\Services\DeliveryWorkflowService;
use Modules\Settlement\Services\SettlementWorkflowService;
use Modules\Shipment\Models\Shipment;

class DeliveryController extends Controller
{
    public function __construct(
        private readonly DeliveryWorkflowService $service,
    ) {}

    /**
     * Admin / branch-manager delivery board.
     *
     * Returns delivery assignments (pending → delivered/failed) with the
     * shipment, rider and route context needed to triage last-mile work.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = DeliveryAssignment::query()
            ->with([
                'shipment.merchant:id,name,code,external_store_id,marketplace_id',
                'shipment.merchant.marketplace:id,name,code',
                'shipment.originBranch',
                'shipment.destinationBranch',
                'rider',
            ]);

        // Branch scoping.
        if ($user->isSuperAdmin() || $user->hasRole('main_admin')) {
            if ($request->filled('branch_id')) {
                $branchId = (int) $request->input('branch_id');
                $query->where(function ($q) use ($branchId) {
                    $q->where('branch_id', $branchId)->orWhere('sub_branch_id', $branchId);
                });
            }
        } elseif ($user->hasRole('branch_manager') || $user->hasRole('sub_branch_manager')) {
            $branchId = (int) $user->branch_id;
            $query->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)->orWhere('sub_branch_id', $branchId);
            });
        } else {
            $query->where('rider_id', $user->id);
        }

        // Status filter. "ready" is an alias for pending (awaiting a rider).
        if ($request->filled('status')) {
            $status = $request->string('status')->toString();
            $query->where('status', $status === 'ready' ? 'pending' : $status);
        }

        if ($request->filled('delivery_type')) {
            $query->where('delivery_type', $request->string('delivery_type')->toString());
        }

        if ($request->filled('search')) {
            $search = trim($request->string('search')->toString());
            if ($search !== '') {
                $query->whereHas('shipment', function ($q) use ($search) {
                    $q->where('tracking_number', 'like', "%{$search}%")
                        ->orWhere('receiver_name', 'like', "%{$search}%")
                        ->orWhere('receiver_phone', 'like', "%{$search}%")
                        ->orWhereHas('merchant', function ($mq) use ($search) {
                            $mq->where('name', 'like', "%{$search}%")
                                ->orWhere('external_store_id', 'like', "%{$search}%")
                                ->orWhere('code', 'like', "%{$search}%");
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

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);

        return ApiResponse::success(
            $query->latest('id')->paginate($perPage)
        );
    }

    /**
     * Summary counts for the delivery board tabs.
     */
    public function summary(Request $request)
    {
        $user = $request->user();

        $base = DeliveryAssignment::query();

        if (! ($user->isSuperAdmin() || $user->hasRole('main_admin'))) {
            if ($user->hasRole('branch_manager') || $user->hasRole('sub_branch_manager')) {
                $branchId = (int) $user->branch_id;
                $base->where(function ($q) use ($branchId) {
                    $q->where('branch_id', $branchId)->orWhere('sub_branch_id', $branchId);
                });
            } else {
                $base->where('rider_id', $user->id);
            }
        }

        $byStatus = (clone $base)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return ApiResponse::success([
            'total' => (clone $base)->count(),
            'by_status' => $byStatus,
        ]);
    }

    /**
     * Riders that can be assigned to a delivery's destination branch.
     */
    public function assignableRiders(Request $request, DeliveryAssignment $delivery)
    {
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
        ]);

        if ($delivery->shipment) {
            $settlements->applyFreeDelivery($delivery->shipment, $data, true);
        }

        $rider = User::query()->findOrFail($data['rider_id']);

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
        ]);

        $rider = User::query()->findOrFail($data['rider_id']);

        $result = $this->service->bulkAssign($data['delivery_ids'], $rider, $request->user());

        $assignedCount = count($result['assigned']);
        $skippedCount = count($result['skipped']);

        return ApiResponse::success(
            $result,
            $skippedCount === 0
                ? "{$assignedCount} deliveries assigned to {$rider->name}."
                : "{$assignedCount} assigned, {$skippedCount} skipped."
        );
    }

    public function failed(Request $request, DeliveryAssignment $delivery)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return ApiResponse::success(
            $this->service->failed($delivery, $request->user(), $data['reason']),
            'Delivery marked as failed.'
        );
    }
}
