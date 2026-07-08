<?php

namespace Modules\Shipment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Shipment\Services\DeliveryWorkflowService;

class StaffDeliveryLifecycleController extends Controller
{
    public function index(Request $request, DeliveryWorkflowService $service): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $service->assignedDeliveriesForRider($request->user()->id),
        ]);
    }

    public function accept(int $delivery, Request $request, DeliveryWorkflowService $service): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $service->accept($delivery, $request->user()->id),
        ]);
    }

    public function outForDelivery(int $delivery, Request $request, DeliveryWorkflowService $service): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $service->outForDelivery($delivery, $request->user()->id),
        ]);
    }

    public function delivered(int $delivery, Request $request, DeliveryWorkflowService $service): JsonResponse
    {
        $data = $request->validate([
            'otp' => ['nullable', 'string'],
            'payment_method' => ['nullable', 'in:cash,qr,card,wallet'],
            'proof_photo_path' => ['nullable', 'string'],
            'signature_path' => ['nullable', 'string'],
            'remarks' => ['nullable', 'string'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $service->delivered($delivery, $request->user()->id, $data),
        ]);
    }

    public function failed(int $delivery, Request $request, DeliveryWorkflowService $service): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string'],
            'remarks' => ['nullable', 'string'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $service->failed($delivery, $request->user()->id, $data),
        ]);
    }
}
