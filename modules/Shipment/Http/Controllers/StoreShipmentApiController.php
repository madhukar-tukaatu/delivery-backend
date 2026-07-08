<?php

namespace Modules\Shipment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Merchant\Models\Merchant;
use Modules\Shipment\Http\Requests\ShipmentOperationsCreateRequest;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Services\ShipmentOperationsService;

class StoreShipmentApiController extends Controller
{
    public function quote(ShipmentOperationsCreateRequest $request, ShipmentOperationsService $service): JsonResponse
    {
        $merchant = $this->merchantFromApiKey($request);

        return response()->json(['data' => $service->quote($merchant, $request->validated())]);
    }

    public function store(ShipmentOperationsCreateRequest $request, ShipmentOperationsService $service): JsonResponse
    {
        $merchant = $this->merchantFromApiKey($request);

        $data = $service->create($merchant, $request->validated(), 'store_api');

        return response()->json(['message' => 'Shipment created successfully.', 'data' => $data], 201);
    }

    public function show(Request $request, string $trackingNumber, ShipmentOperationsService $service): JsonResponse
    {
        $merchant = $this->merchantFromApiKey($request);

        $shipment = Shipment::where('merchant_id', $merchant->id)
            ->where('tracking_number', $trackingNumber)
            ->firstOrFail();

        return response()->json(['data' => $service->showPayload($shipment)]);
    }

    private function merchantFromApiKey(Request $request): Merchant
    {
        $key = $request->header('X-API-KEY');
        abort_unless($key, 401, 'X-API-KEY header is required.');

        $apiKey = DB::table('merchant_api_keys')
            ->where('key', $key)
            ->where(function ($q) {
                $q->where('is_active', true)->orWhereNull('is_active');
            })
            ->first();

        abort_unless($apiKey, 401, 'Invalid API key.');

        return Merchant::findOrFail($apiKey->merchant_id);
    }
}
