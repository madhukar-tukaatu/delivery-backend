<?php

namespace Modules\Routing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Modules\Routing\Services\ShipmentRoutingService;

class RoutingQuoteController extends Controller
{
    public function quote(Request $request, ShipmentRoutingService $routing)
    {
        $data = $request->validate([
            'pickup_lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['required', 'numeric', 'between:-180,180'],
            'delivery_lat' => ['required', 'numeric', 'between:-90,90'],
            'delivery_lng' => ['required', 'numeric', 'between:-180,180'],
            'weight' => ['nullable', 'numeric', 'min:0.1'],
            'pod_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        return ApiResponse::success($routing->quote($data), 'Route quote calculated.');
    }
}
