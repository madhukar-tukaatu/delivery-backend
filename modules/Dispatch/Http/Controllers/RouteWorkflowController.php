<?php

namespace Modules\Dispatch\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Modules\Dispatch\Services\RouteWorkflowService;
use Modules\Shipment\Models\Shipment;

class RouteWorkflowController extends Controller
{
    public function receiveOriginSubBranch(Request $request, Shipment $shipment, RouteWorkflowService $service)
    {
        $data = $request->validate(['remarks' => ['nullable', 'string', 'max:500']]);
        return ApiResponse::success($service->receiveOriginSubBranch($shipment, $request->user(), $data['remarks'] ?? null), 'Received at origin sub-branch.');
    }

    public function dispatchNextStep(Request $request, Shipment $shipment, RouteWorkflowService $service)
    {
        $data = $request->validate(['remarks' => ['nullable', 'string', 'max:500']]);
        return ApiResponse::success($service->dispatchNextStep($shipment, $request->user(), $data['remarks'] ?? null), 'Shipment dispatched to next route point.');
    }

    public function receiveCurrentStep(Request $request, Shipment $shipment, RouteWorkflowService $service)
    {
        $data = $request->validate(['remarks' => ['nullable', 'string', 'max:500']]);
        return ApiResponse::success($service->receiveCurrentStep($shipment, $request->user(), $data['remarks'] ?? null), 'Route step received.');
    }
}
