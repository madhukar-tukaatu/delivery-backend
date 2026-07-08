<?php

namespace Modules\Shipment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Shipment\Services\DeliveryWorkflowService;

class StaffDeliveryOperationsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = DB::table('delivery_assignments')
            ->leftJoin('shipments', 'shipments.id', '=', 'delivery_assignments.shipment_id')
            ->where('delivery_assignments.rider_id', $request->user()->id)
            ->select('delivery_assignments.*', 'shipments.tracking_number', 'shipments.customer_name', 'shipments.customer_phone', 'shipments.delivery_address', 'shipments.total_collectable')
            ->latest('delivery_assignments.id')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function accept(Request $request, int $delivery, DeliveryWorkflowService $service): JsonResponse
    {
        $row = $service->accept($delivery, $request->user()->id);

        return response()->json(['message' => 'Delivery accepted.', 'data' => $row]);
    }

    public function outForDelivery(Request $request, int $delivery, DeliveryWorkflowService $service): JsonResponse
    {
        $row = $service->outForDelivery($delivery, $request->user()->id);

        return response()->json(['message' => 'Marked out for delivery.', 'data' => $row]);
    }

    public function delivered(Request $request, int $delivery, DeliveryWorkflowService $service): JsonResponse
    {
        $payload = $request->validate([
            'otp_verified' => ['nullable', 'boolean'],
            'proof_type' => ['nullable', 'string', 'max:50'],
            'proof_value' => ['nullable', 'string', 'max:1000'],
            'payment_method' => ['nullable', 'string', 'max:50'],
        ]);

        $row = $service->delivered($delivery, $request->user()->id, $payload);

        return response()->json(['message' => 'Shipment delivered.', 'data' => $row]);
    }

    public function failed(Request $request, int $delivery, DeliveryWorkflowService $service): JsonResponse
    {
        $payload = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $row = $service->failed($delivery, $request->user()->id, $payload);

        return response()->json(['message' => 'Delivery marked failed.', 'data' => $row]);
    }
}
