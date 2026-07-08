<?php

namespace Modules\Shipment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Shipment\Services\PickupWorkflowService;

class StaffPickupLifecycleController extends Controller
{
    public function index(Request $request, PickupWorkflowService $service): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $service->assignedPickupsForStaff($request->user()->id),
        ]);
    }

    public function accept(int $pickup, Request $request, PickupWorkflowService $service): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $service->accept($pickup, $request->user()->id),
        ]);
    }

    public function pickedUp(int $pickup, Request $request, PickupWorkflowService $service): JsonResponse
    {
        $data = $request->validate([
            'remarks' => ['nullable', 'string'],
            'parcel_count' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $service->markPickedUp($pickup, $request->user()->id, $data),
        ]);
    }
}
