<?php

namespace Modules\Shipment\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Merchant\Models\Merchant;
use Modules\Shipment\Http\Requests\MerchantCreateShipmentRequest;
use Modules\Shipment\Services\MerchantShipmentCreateViewService;

class MerchantShipmentCreateViewController extends Controller
{
    public function __construct(private readonly MerchantShipmentCreateViewService $service)
    {
    }

    public function pickupLocations(Request $request): JsonResponse
    {
        $merchant = $this->merchantFromUser($request);

        $locations = DB::table('merchant_pickup_locations')
            ->where('merchant_id', $merchant->id)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $locations,
        ]);
    }

    public function quote(MerchantCreateShipmentRequest $request): JsonResponse
    {
        $merchant = $this->merchantFromUser($request);
        $quote = $this->service->quote($merchant, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Fare and route calculated successfully.',
            'data' => $quote,
        ]);
    }

    public function store(MerchantCreateShipmentRequest $request): JsonResponse
    {
        $merchant = $this->merchantFromUser($request);

        $result = $this->service->create(
            actor: $request->user(),
            merchant: $merchant,
            payload: $request->validated(),
            source: 'merchant_panel'
        );

        return response()->json([
            'success' => true,
            'message' => 'Shipment created successfully.',
            'data' => $result,
        ], 201);
    }

    public function show(Request $request, int|string $shipment): JsonResponse
    {
        $merchant = $this->merchantFromUser($request);
        $result = $this->service->show($merchant, $shipment);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    private function merchantFromUser(Request $request): Merchant
    {
        $user = $request->user();

        $merchant = $user->merchant ?? null;

        if (!$merchant && isset($user->merchant_id)) {
            $merchant = Merchant::find($user->merchant_id);
        }

        abort_if(!$merchant, 403, 'Merchant profile not found for this user.');

        $status = strtolower((string) ($merchant->status ?? ''));
        abort_if(!in_array($status, ['active', 'approved'], true), 403, 'Merchant is not active yet.');

        return $merchant;
    }
}
