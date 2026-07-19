<?php

namespace Modules\Shipment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\CourierStatus;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Services\ShipmentService;

class GatewayShipmentController extends Controller
{
    public function store(Request $request, ShipmentService $service)
    {
        $merchant = $request->attributes->get('merchant');
        $data = $request->validate([
            'merchant_order_id' => ['required', 'string'],
            'pickup_name' => ['nullable', 'string'],
            'pickup_phone' => ['nullable', 'string'],
            'pickup_address' => ['required', 'string'],
            'pickup_city' => ['nullable', 'string'],
            'pickup_area' => ['nullable', 'string'],
            'pickup_lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['required', 'numeric', 'between:-180,180'],
            'customer_name' => ['required', 'string'],
            'customer_phone' => ['required', 'string'],
            'customer_email' => ['nullable', 'email'],
            'customer_address' => ['required', 'string'],
            'customer_city' => ['nullable', 'string'],
            'customer_area' => ['nullable', 'string'],
            'delivery_lat' => ['required', 'numeric', 'between:-90,90'],
            'delivery_lng' => ['required', 'numeric', 'between:-180,180'],
            'parcel_type' => ['nullable', 'string'],
            'product_description' => ['nullable', 'string'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'weight' => ['nullable', 'numeric', 'min:0.1'],
            'declared_value' => ['nullable', 'numeric', 'min:0'],
            'fragile' => ['nullable', 'boolean'],
            'payment_type' => ['nullable', 'in:prepaid,pod,to_pay'],
            'pod_amount' => ['nullable', 'numeric', 'min:0'],
            'delivery_charge_paid_by' => ['nullable', 'in:merchant,customer'],
        ]);
        try {
            $shipment = $service->create($data, null, $merchant->id, 'merchant_api');
        } catch (QueryException $e) {
            return ApiResponse::error('Duplicate merchant order id.', 409);
        }
        return ApiResponse::success([
            'tracking_number' => $shipment->tracking_number,
            'shipment_id' => $shipment->id,
            'origin_branch' => $shipment->originBranch?->name,
            'origin_sub_branch' => $shipment->originSubBranch?->name,
            'destination_branch' => $shipment->destinationBranch?->name,
            'destination_sub_branch' => $shipment->destinationSubBranch?->name,
            'route_distance_km' => (float) $shipment->route_distance_km,
            'route_fee' => (float) $shipment->route_fee,
            'delivery_charge' => (float) $shipment->delivery_charge,
            'pod_charge' => (float) $shipment->pod_charge,
            'total_collectable_amount' => (float) $shipment->total_collectable_amount,
            'delivery_charge_breakdown' => $shipment->delivery_charge_breakdown,
            'estimated_delivery_time' => $shipment->estimated_delivery_time,
            'status' => $shipment->status,
            'merchant_status' => $shipment->merchant_status,
        ], 'Delivery order created.', 201);
    }

    public function show(Request $request, string $trackingNumber)
    {
        $merchant = $request->attributes->get('merchant');
        $shipment = Shipment::where('merchant_id', $merchant->id)
            ->where('tracking_number', $trackingNumber)
            ->with(['trackingEvents', 'originBranch', 'originSubBranch', 'destinationBranch', 'destinationSubBranch', 'routeSteps.fromBranch', 'routeSteps.toBranch'])
            ->firstOrFail();
        return ApiResponse::success($shipment);
    }

    public function cancel(Request $request, string $trackingNumber, ShipmentService $service)
    {
        $merchant = $request->attributes->get('merchant');
        $shipment = Shipment::where('merchant_id', $merchant->id)->where('tracking_number', $trackingNumber)->firstOrFail();
        if (!in_array($shipment->status, [CourierStatus::BOOKED, CourierStatus::PICKUP_ASSIGNED], true)) {
            return ApiResponse::error('Shipment cannot be cancelled after pickup/dispatch.', 422);
        }
        return ApiResponse::success($service->updateStatus($shipment, CourierStatus::CANCELLED, null, 'Cancelled by gateway API.'));
    }
}
