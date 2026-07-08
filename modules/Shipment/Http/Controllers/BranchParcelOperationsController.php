<?php

namespace Modules\Shipment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Services\TransferWorkflowService;

class BranchParcelOperationsController extends Controller
{
    public function receiveOrigin(Request $request, Shipment $shipment, TransferWorkflowService $service): JsonResponse
    {
        $shipment = $service->receiveOrigin($shipment, $request->user()->id, $request->input('note'));

        return response()->json(['message' => 'Parcel received at origin hub.', 'data' => $shipment]);
    }

    public function createTransfer(Request $request, Shipment $shipment, TransferWorkflowService $service): JsonResponse
    {
        $payload = $request->validate([
            'vehicle_id' => ['nullable', 'integer'],
            'vehicle_number' => ['nullable', 'string', 'max:100'],
            'seal_number' => ['nullable', 'string', 'max:100'],
        ]);

        $batch = $service->createTransfer($shipment, $request->user()->id, $payload);

        return response()->json(['message' => 'Transfer batch created.', 'data' => $batch]);
    }

    public function dispatchTransfer(Request $request, int $batch, TransferWorkflowService $service): JsonResponse
    {
        $row = $service->dispatchTransfer($batch, $request->user()->id);

        return response()->json(['message' => 'Transfer dispatched.', 'data' => $row]);
    }

    public function receiveTransfer(Request $request, int $batch, TransferWorkflowService $service): JsonResponse
    {
        $row = $service->receiveTransfer($batch, $request->user()->id);

        return response()->json(['message' => 'Transfer received.', 'data' => $row]);
    }
}
