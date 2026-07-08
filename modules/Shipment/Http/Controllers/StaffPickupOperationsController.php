<?php

namespace Modules\Shipment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Shipment\Services\PickupWorkflowService;

class StaffPickupOperationsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = DB::table('pickup_requests')
            ->leftJoin('shipments', 'shipments.id', '=', 'pickup_requests.shipment_id')
            ->where(function ($q) use ($request) {
                $q->where('pickup_requests.assigned_to', $request->user()->id)
                    ->orWhereNull('pickup_requests.assigned_to');
            })
            ->select('pickup_requests.*', 'shipments.tracking_number', 'shipments.customer_name', 'shipments.customer_phone')
            ->latest('pickup_requests.id')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function accept(Request $request, int $pickup, PickupWorkflowService $service): JsonResponse
    {
        $row = $service->accept($pickup, $request->user()->id);

        return response()->json(['message' => 'Pickup accepted.', 'data' => $row]);
    }

    public function pickedUp(Request $request, int $pickup, PickupWorkflowService $service): JsonResponse
    {
        $row = $service->pickedUp($pickup, $request->user()->id, $request->input('note'));

        return response()->json(['message' => 'Pickup completed.', 'data' => $row]);
    }
}
