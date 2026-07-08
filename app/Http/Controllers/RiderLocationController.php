<?php

namespace App\Http\Controllers;

use App\Events\RiderLocationUpdated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class RiderLocationController extends Controller
{
    public function store(Request $request)
    {
        $user = $request->user();

        if (!$user->hasRole('delivery_rider') && !$user->hasRole('rider')) {
            abort(403, 'Only riders can update live location.');
        }

        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'shipment_id' => ['nullable', 'integer'],
            'heading' => ['nullable', 'numeric'],
            'speed' => ['nullable', 'numeric'],
        ]);

        $key = "rider:{$user->id}:location";

        Redis::hmset($key, [
            'lat' => (string) $data['lat'],
            'lng' => (string) $data['lng'],
            'shipment_id' => (string) ($data['shipment_id'] ?? ''),
            'heading' => (string) ($data['heading'] ?? ''),
            'speed' => (string) ($data['speed'] ?? ''),
            'updated_at' => now()->toISOString(),
        ]);

        Redis::expire($key, 300);

        event(new RiderLocationUpdated(
            riderId: $user->id,
            lat: (float) $data['lat'],
            lng: (float) $data['lng'],
            shipmentId: isset($data['shipment_id']) ? (int) $data['shipment_id'] : null,
            heading: isset($data['heading']) ? (float) $data['heading'] : null,
            speed: isset($data['speed']) ? (float) $data['speed'] : null,
        ));

        return response()->json([
            'message' => 'Location updated.',
            'data' => [
                'rider_id' => $user->id,
                'lat' => (float) $data['lat'],
                'lng' => (float) $data['lng'],
                'updated_at' => now()->toISOString(),
            ],
        ]);
    }
}
