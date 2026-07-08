<?php

namespace Modules\Shipment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Shipment\Http\Requests\ShipmentLifecycleCreateRequest;
use Modules\Shipment\Services\ShipmentLifecycleService;

class MerchantShipmentLifecycleController extends Controller
{
    public function __construct(private ShipmentLifecycleService $service) {}

    public function quote(ShipmentLifecycleCreateRequest $request): JsonResponse
    {
        $merchantId = $request->user()->merchant->id ?? null;
        abort_unless($merchantId, 403, 'Merchant profile not found.');

        return response()->json([
            'success' => true,
            'data' => $this->service->quote((int) $merchantId, $request->validated()),
        ]);
    }

    public function store(ShipmentLifecycleCreateRequest $request): JsonResponse
    {
        $merchantId = $request->user()->merchant->id ?? null;
        abort_unless($merchantId, 403, 'Merchant profile not found.');

        return response()->json(
            $this->service->create(
                merchantId: (int) $merchantId,
                payload: $request->validated(),
                source: 'merchant_panel',
                actorId: $request->user()->id,
            ),
            201
        );
    }

    public function show(string $shipment, ShipmentLifecycleService $service): JsonResponse
    {
        $merchantId = request()->user()->merchant->id ?? null;
        abort_unless($merchantId, 403, 'Merchant profile not found.');

        return response()->json([
            'success' => true,
            'data' => $service->showForMerchant((int) $merchantId, $shipment),
        ]);
    }
}
