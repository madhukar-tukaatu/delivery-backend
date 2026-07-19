<?php

namespace Modules\Delivery\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Modules\Delivery\Models\DeliveryAssignment;
use Modules\Delivery\Services\DeliveryWorkflowService;

class DeliveryController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = DeliveryAssignment::query()
            ->with(['shipment.merchant', 'rider'])
            ->whereIn('status', ['assigned', 'out_for_delivery']);

        if ($user->isSuperAdmin() || $user->hasRole('main_admin')) {
            // all
        } elseif ($user->hasRole('branch_manager') || $user->hasRole('sub_branch_manager')) {
            $query->where(function ($q) use ($user) {
                $q->where('branch_id', $user->branch_id)
                    ->orWhere('sub_branch_id', $user->branch_id);
            });
        } else {
            $query->where('rider_id', $user->id);
        }

        return ApiResponse::success($query->latest()->paginate((int) $request->get('per_page', 20)));
    }

    public function outForDelivery(Request $request, DeliveryAssignment $delivery, DeliveryWorkflowService $service)
    {
        return ApiResponse::success($service->outForDelivery($delivery, $request->user()), 'Marked out for delivery.');
    }

    public function delivered(Request $request, DeliveryAssignment $delivery, DeliveryWorkflowService $service)
    {
        $data = $request->validate([
            'pod_collected_amount' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        return ApiResponse::success($service->delivered($delivery, $request->user(), $data), 'Shipment delivered.');
    }

    public function failed(Request $request, DeliveryAssignment $delivery, DeliveryWorkflowService $service)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        return ApiResponse::success($service->failed($delivery, $request->user(), $data['reason']), 'Delivery marked as failed.');
    }
}
