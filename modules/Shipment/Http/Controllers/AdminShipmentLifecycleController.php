<?php

namespace Modules\Shipment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Merchant\Models\Merchant;
use Modules\Shipment\Http\Requests\ShipmentOperationsCreateRequest;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Services\DeliveryWorkflowService;
use Modules\Shipment\Services\PickupWorkflowService;
use Modules\Shipment\Services\ShipmentOperationsService;

class AdminShipmentLifecycleController  extends Controller
{
    public function quote(ShipmentOperationsCreateRequest $request, ShipmentOperationsService $service): JsonResponse
    {
        $merchant = Merchant::findOrFail($request->input('merchant_id'));

        return response()->json(['data' => $service->quote($merchant, $request->validated())]);
    }

    public function store(ShipmentOperationsCreateRequest $request, ShipmentOperationsService $service): JsonResponse
    {
        $merchant = Merchant::findOrFail($request->input('merchant_id'));

        $data = $service->create($merchant, $request->validated(), 'admin_panel', $request->user());

        return response()->json(['message' => 'Shipment created successfully.', 'data' => $data], 201);
    }

    public function show(Shipment $shipment, ShipmentOperationsService $service): JsonResponse
    {
        return response()->json(['data' => $service->showPayload($shipment)]);
    }

    public function assignPickup(Request $request, Shipment $shipment, PickupWorkflowService $pickupWorkflowService): JsonResponse
    {
        $data = $request->validate(['staff_id' => ['required', 'integer']]);

        $pickup = $pickupWorkflowService->assign($shipment->id, (int) $data['staff_id'], $request->user()->id);

        return response()->json(['message' => 'Pickup assigned.', 'data' => $pickup]);
    }

    public function assignDelivery(Request $request, Shipment $shipment, DeliveryWorkflowService $deliveryWorkflowService): JsonResponse
    {
        $data = $request->validate(['rider_id' => ['required', 'integer']]);

        $delivery = $deliveryWorkflowService->assign($shipment, (int) $data['rider_id'], $request->user()->id);

        return response()->json(['message' => 'Delivery rider assigned.', 'data' => $delivery]);
    }
}
