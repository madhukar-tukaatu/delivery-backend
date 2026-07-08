<?php

namespace Modules\Dispatch\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\CourierStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Dispatch\Models\DispatchManifest;
use Modules\Dispatch\Models\DispatchManifestItem;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Services\ShipmentService;

class DispatchController extends Controller
{
    public function index(Request $request)
    {
        $query = DispatchManifest::with('items.shipment')->latest();
        if ($request->filled('status')) $query->where('status', $request->status);
        return ApiResponse::success($query->paginate((int) $request->get('per_page', 20)));
    }

    public function store(Request $request, ShipmentService $shipmentService)
    {
        $data = $request->validate([
            'from_branch_id' => ['nullable', 'exists:branches,id'],
            'from_sub_branch_id' => ['nullable', 'exists:branches,id'],
            'to_branch_id' => ['nullable', 'exists:branches,id'],
            'to_sub_branch_id' => ['nullable', 'exists:branches,id'],
            'vehicle_number' => ['nullable', 'string'],
            'driver_name' => ['nullable', 'string'],
            'seal_number' => ['nullable', 'string'],
            'shipment_ids' => ['required', 'array', 'min:1'],
            'shipment_ids.*' => ['exists:shipments,id'],
        ]);

        $manifest = DB::transaction(function () use ($data, $request, $shipmentService) {
            $manifest = DispatchManifest::create([
                'manifest_number' => 'MF-'.now()->format('YmdHis').'-'.random_int(100, 999),
                'from_branch_id' => $data['from_branch_id'] ?? null,
                'from_sub_branch_id' => $data['from_sub_branch_id'] ?? null,
                'to_branch_id' => $data['to_branch_id'] ?? null,
                'to_sub_branch_id' => $data['to_sub_branch_id'] ?? null,
                'vehicle_number' => $data['vehicle_number'] ?? null,
                'driver_name' => $data['driver_name'] ?? null,
                'seal_number' => $data['seal_number'] ?? null,
                'status' => 'dispatched',
                'created_by' => $request->user()->id,
                'dispatched_at' => now(),
            ]);

            foreach ($data['shipment_ids'] as $shipmentId) {
                DispatchManifestItem::create(['dispatch_manifest_id' => $manifest->id, 'shipment_id' => $shipmentId, 'status' => 'sent']);
                $shipment = Shipment::find($shipmentId);
                if ($shipment) {
                    $shipment->update(['current_branch_id' => $data['from_branch_id'] ?? $shipment->current_branch_id, 'current_sub_branch_id' => $data['from_sub_branch_id'] ?? $shipment->current_sub_branch_id]);
                    $shipmentService->updateStatus($shipment, CourierStatus::IN_TRANSIT, $request->user()->id, 'Dispatched in manifest '.$manifest->manifest_number);
                }
            }
            return $manifest;
        });

        return ApiResponse::success($manifest->load('items.shipment'), 'Manifest dispatched.', 201);
    }

    public function receive(Request $request, DispatchManifest $dispatch, ShipmentService $shipmentService)
    {
        $data = $request->validate([
            'received_shipment_ids' => ['nullable', 'array'],
            'received_shipment_ids.*' => ['exists:shipments,id'],
            'remarks' => ['nullable', 'string'],
        ]);
        $receivedIds = $data['received_shipment_ids'] ?? $dispatch->items()->pluck('shipment_id')->all();

        DB::transaction(function () use ($dispatch, $receivedIds, $request, $shipmentService) {
            $dispatch->update(['status' => 'received', 'received_by' => $request->user()->id, 'received_at' => now()]);
            foreach ($dispatch->items as $item) {
                $received = in_array($item->shipment_id, $receivedIds, true);
                $item->update(['status' => $received ? 'received' : 'missing']);
                if ($received) {
                    $shipment = $item->shipment;
                    $shipment->update(['current_branch_id' => $dispatch->to_branch_id, 'current_sub_branch_id' => $dispatch->to_sub_branch_id]);
                    $shipmentService->updateStatus($shipment, CourierStatus::RECEIVED_AT_DESTINATION, $request->user()->id, 'Received from manifest '.$dispatch->manifest_number);
                }
            }
        });

        return ApiResponse::success($dispatch->fresh('items.shipment'), 'Manifest received.');
    }
}
