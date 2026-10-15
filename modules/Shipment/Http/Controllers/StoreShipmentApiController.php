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

    public function previewDeliveryLocation(
        Request $request,
        string $trackingNumber,
        \Modules\Shipment\Services\ShipmentService $service
    ): JsonResponse {
        $merchant = $this->merchantFromApiKey($request);

        $shipment = Shipment::where('merchant_id', $merchant->id)
            ->where(function ($q) use ($trackingNumber) {
                $q->where('tracking_number', $trackingNumber)
                  ->orWhere('merchant_order_id', $trackingNumber);
            })
            ->firstOrFail();

        $data = $request->validate([
            'delivery_lat'      => ['required', 'numeric', 'between:-90,90'],
            'delivery_lng'      => ['required', 'numeric', 'between:-180,180'],
            'delivery_address'  => ['nullable', 'string', 'max:500'],
            'delivery_city'     => ['nullable', 'string', 'max:100'],
            'delivery_area'     => ['nullable', 'string', 'max:100'],
        ]);

        $preview = $service->previewDeliveryLocationRecalculation($shipment, $data);

        return response()->json([
            'success' => true,
            'message' => 'Delivery location recalculation previewed.',
            'data'    => $preview,
        ]);
    }

    public function updateDeliveryLocation(
        Request $request,
        string $trackingNumber,
        \Modules\Shipment\Services\ShipmentService $service
    ): JsonResponse {
        $merchant = $this->merchantFromApiKey($request);

        $shipment = Shipment::where('merchant_id', $merchant->id)
            ->where(function ($q) use ($trackingNumber) {
                $q->where('tracking_number', $trackingNumber)
                  ->orWhere('merchant_order_id', $trackingNumber);
            })
            ->firstOrFail();

        $data = $request->validate([
            'delivery_lat'      => ['required', 'numeric', 'between:-90,90'],
            'delivery_lng'      => ['required', 'numeric', 'between:-180,180'],
            'delivery_address'  => ['nullable', 'string', 'max:500'],
            'delivery_city'     => ['nullable', 'string', 'max:100'],
            'delivery_area'     => ['nullable', 'string', 'max:100'],
            'receiver_name'     => ['nullable', 'string', 'max:150'],
            'receiver_phone'    => ['nullable', 'string', 'max:50'],
            'receiver_email'    => ['nullable', 'email', 'max:150'],
            'preview_only'      => ['nullable', 'boolean'],
            'dry_run'           => ['nullable', 'boolean'],
            'reassign_rider'    => ['nullable', 'boolean'],
        ]);

        if ($request->boolean('preview_only') || $request->boolean('dry_run')) {
            $preview = $service->previewDeliveryLocationRecalculation($shipment, $data);

            return response()->json([
                'success' => true,
                'message' => 'Delivery location recalculation previewed (dry run).',
                'data'    => $preview,
            ]);
        }

        $updated = $service->updateDeliveryLocation($shipment, $data);

        $payload = $updated->toArray();
        if (isset($updated->pricing_update)) {
            $payload['pricing_update'] = $updated->pricing_update;
        }

        return response()->json([
            'success' => true,
            'message' => 'Delivery location updated and delivery charge recalculated successfully.',
            'data'    => $payload,
        ]);
    }

    private function merchantFromApiKey(Request $request): Merchant
    {
        $key = $request->header('X-API-KEY')
            ?? $request->header('X-Tukaatu-Key')
            ?? $request->bearerToken();

        abort_unless($key, 401, 'API key header is required (X-API-KEY or X-Tukaatu-Key).');

        $keyHash = hash('sha256', (string) $key);

        $apiKey = DB::table('merchant_api_keys')
            ->where(function ($q) use ($key, $keyHash) {
                $q->where('key', $key)
                  ->orWhere('key_hash', $keyHash);
            })
            ->where(function ($q) {
                $q->where('is_active', true)->orWhereNull('is_active');
            })
            ->first();

        abort_unless($apiKey, 401, 'Invalid API key.');

        return Merchant::findOrFail($apiKey->merchant_id);
    }
}
