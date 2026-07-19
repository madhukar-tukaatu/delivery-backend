<?php

namespace Modules\Shipment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\CourierStatus;
use Illuminate\Http\Request;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Services\ShipmentService;

class ShipmentController extends Controller
{
    public function index(Request $request)
    {
        $query = Shipment::with(['merchant', 'originBranch', 'originSubBranch', 'destinationBranch', 'destinationSubBranch'])->latest();
        if ($request->filled('_scope_branch_id')) {
            $branchId = $request->get('_scope_branch_id');
            $query->where(function ($x) use ($branchId) {
                $x->where('origin_branch_id', $branchId)
                  ->orWhere('origin_sub_branch_id', $branchId)
                  ->orWhere('destination_branch_id', $branchId)
                  ->orWhere('destination_sub_branch_id', $branchId)
                  ->orWhere('current_branch_id', $branchId)
                  ->orWhere('current_sub_branch_id', $branchId);
            });
        }
        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('merchant_id')) $query->where('merchant_id', $request->merchant_id);
        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(function ($x) use ($q) {
                $x->where('tracking_number', 'like', "%$q%")
                  ->orWhere('merchant_order_id', 'like', "%$q%")
                  ->orWhere('receiver_name', 'like', "%$q%")
                  ->orWhere('receiver_phone', 'like', "%$q%");
            });
        }
        return ApiResponse::success($query->paginate((int) $request->get('per_page', 20)));
    }

    public function store(Request $request, ShipmentService $service)
    {
        $data = $this->validatedShipment($request);
        $shipment = $service->create($data, $request->user()->id, $data['merchant_id'] ?? null, 'manual');
        return ApiResponse::success($shipment, 'Shipment created.', 201);
    }

    public function update(Request $request, Shipment $shipment)
    {
        $data = $this->validatedShipment($request);
        $shipment->update($data);

        if ($this->shouldReroute($data)) {
            app(\Modules\Routing\Services\ShipmentRoutingService::class)->applyToShipment($shipment, [
                'pickup_lat' => $data['pickup_lat'],
                'pickup_lng' => $data['pickup_lng'],
                'delivery_lat' => $data['delivery_lat'],
                'delivery_lng' => $data['delivery_lng'],
                'weight' => $data['weight'] ?? $shipment->weight ?? 1,
                'pod_amount' => $data['pod_amount'] ?? $shipment->pod_amount ?? 0,
            ]);
        }

        return ApiResponse::success($shipment->fresh([
            'merchant', 'items', 'trackingEvents', 'originBranch', 'originSubBranch', 'destinationBranch',
            'destinationSubBranch', 'currentBranch', 'currentSubBranch', 'routeSteps.fromBranch', 'routeSteps.toBranch'
        ]), 'Shipment updated.');
    }

    public function show(Shipment $shipment)
    {
        return ApiResponse::success($shipment->load([
            'merchant', 'items', 'trackingEvents', 'originBranch', 'originSubBranch', 'destinationBranch',
            'destinationSubBranch', 'currentBranch', 'currentSubBranch', 'routeSteps.fromBranch', 'routeSteps.toBranch'
        ]));
    }

    public function status(Request $request, Shipment $shipment, ShipmentService $service)
    {
        $data = $request->validate([
            'status' => ['required', 'string'],
            'remarks' => ['nullable', 'string'],
        ]);
        $shipment = $service->updateStatus($shipment, $data['status'], $request->user()->id, $data['remarks'] ?? null);
        return ApiResponse::success($shipment, 'Shipment status updated.');
    }

    public function cancel(Request $request, Shipment $shipment, ShipmentService $service)
    {
        $shipment = $service->updateStatus($shipment, CourierStatus::CANCELLED, $request->user()->id, $request->get('remarks', 'Shipment cancelled.'));
        return ApiResponse::success($shipment, 'Shipment cancelled.');
    }

    private function validatedShipment(Request $request): array
    {
        return $request->validate([
            'merchant_id' => ['nullable', 'exists:merchants,id'],
            'merchant_order_id' => ['nullable', 'string'],
            'manual_branch_override' => ['nullable', 'boolean'],
            'origin_branch_id' => ['nullable', 'exists:branches,id'],
            'origin_sub_branch_id' => ['nullable', 'exists:branches,id'],
            'destination_branch_id' => ['nullable', 'exists:branches,id'],
            'destination_sub_branch_id' => ['nullable', 'exists:branches,id'],
            'pickup_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'delivery_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'delivery_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'sender_name' => ['nullable', 'string'],
            'sender_phone' => ['nullable', 'string'],
            'sender_address' => ['nullable', 'string'],
            'sender_city' => ['nullable', 'string'],
            'sender_area' => ['nullable', 'string'],
            'receiver_name' => ['required', 'string'],
            'receiver_phone' => ['required', 'string'],
            'receiver_email' => ['nullable', 'email'],
            'receiver_address' => ['required', 'string'],
            'receiver_city' => ['nullable', 'string'],
            'receiver_area' => ['nullable', 'string'],
            'parcel_type' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'weight' => ['nullable', 'numeric', 'min:0.1'],
            'declared_value' => ['nullable', 'numeric', 'min:0'],
            'fragile' => ['nullable', 'boolean'],
            'payment_type' => ['nullable', 'in:prepaid,pod,to_pay'],
            'pod_amount' => ['nullable', 'numeric', 'min:0'],
            'delivery_charge' => ['nullable', 'numeric', 'min:0'],
            'pod_charge' => ['nullable', 'numeric', 'min:0'],
            'delivery_charge_paid_by' => ['nullable', 'in:merchant,customer'],
            'remarks' => ['nullable', 'string'],
        ]);
    }

    private function shouldReroute(array $data): bool
    {
        if (!empty($data['manual_branch_override'])) {
            return false;
        }

        return !empty($data['pickup_lat'])
            && !empty($data['pickup_lng'])
            && !empty($data['delivery_lat'])
            && !empty($data['delivery_lng']);
    }
}
