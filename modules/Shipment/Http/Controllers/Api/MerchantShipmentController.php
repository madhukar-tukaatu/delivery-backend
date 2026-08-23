<?php

namespace Modules\Shipment\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Modules\Shipment\Http\Requests\MerchantCreateShipmentRequest;
use Modules\Shipment\Services\MerchantShipmentCreationService;

class MerchantShipmentController extends Controller
{
    public function store(
        MerchantCreateShipmentRequest $request,
        MerchantShipmentCreationService $service
    ) {
        $merchant = $request->user()->merchant;

        $shipment = $service->create(
            $merchant,
            $request->validated(),
            $request->user()->id
        );

        return ApiResponse::success(
            $shipment,
            'Shipment created successfully.'
        );
    }
}