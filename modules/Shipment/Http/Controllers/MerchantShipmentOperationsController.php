<?php

namespace Modules\Shipment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Shipment\Http\Requests\ShipmentOperationsCreateRequest;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Services\ShipmentOperationsService;

class MerchantShipmentOperationsController extends Controller
{
    public function pickupLocations(Request $request): JsonResponse
    {
        $merchant = $request->user()->merchant;
        abort_unless($merchant, 403, 'Merchant profile not found.');

        $rows = DB::table('merchant_pickup_locations')
            ->where('merchant_id', $merchant->id)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $rows]);
    }

    public function index(Request $request): JsonResponse
    {
        $merchant = $request->user()->merchant;
        abort_unless($merchant, 403, 'Merchant profile not found.');

        $rows = Shipment::where('merchant_id', $merchant->id)
            ->latest('id')
            ->paginate($request->integer('per_page', 20));

        return response()->json($rows);
    }

    public function quote(ShipmentOperationsCreateRequest $request, ShipmentOperationsService $service): JsonResponse
    {
        $merchant = $request->user()->merchant;
        abort_unless($merchant, 403, 'Merchant profile not found.');

        return response()->json(['data' => $service->quote($merchant, $request->validated())]);
    }

    public function store(ShipmentOperationsCreateRequest $request, ShipmentOperationsService $service): JsonResponse
    {
        $merchant = $request->user()->merchant;
        abort_unless($merchant, 403, 'Merchant profile not found.');

        $data = $service->create($merchant, $request->validated(), 'merchant_panel', $request->user());

        return response()->json(['message' => 'Shipment created successfully.', 'data' => $data], 201);
    }

    public function show(Request $request, Shipment $shipment, ShipmentOperationsService $service): JsonResponse
    {
        $merchant = $request->user()->merchant;
        abort_unless($merchant && (int) $shipment->merchant_id === (int) $merchant->id, 403, 'Not allowed.');

        return response()->json(['data' => $service->showPayload($shipment)]);
    }
}
