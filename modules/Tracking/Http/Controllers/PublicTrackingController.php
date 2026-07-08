<?php

namespace Modules\Tracking\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Modules\Shipment\Models\Shipment;

class PublicTrackingController extends Controller
{
    public function show(string $trackingNumber)
    {
        $shipment = Shipment::where('tracking_number', $trackingNumber)
            ->with(['trackingEvents' => fn ($q) => $q->whereIn('visibility', ['public', 'merchant'])->oldest()])
            ->first();

        if (!$shipment) {
            return ApiResponse::error('Tracking number not found.', 404);
        }

        return ApiResponse::success([
            'tracking_number' => $shipment->tracking_number,
            'status' => $shipment->merchant_status,
            'current_status' => $shipment->status,
            'receiver_city' => $shipment->receiver_city,
            'receiver_area' => $shipment->receiver_area,
            'events' => $shipment->trackingEvents,
        ]);
    }
}
