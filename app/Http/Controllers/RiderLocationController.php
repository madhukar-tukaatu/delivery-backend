<?php

namespace App\Http\Controllers;

use App\Events\RiderLocationUpdated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Modules\Delivery\Models\DeliveryAssignment;
use Modules\Shipment\Models\Shipment;

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
            'tracking_number' => ['nullable', 'string', 'max:191'],
            'heading' => ['nullable', 'numeric'],
            'speed' => ['nullable', 'numeric'],
        ]);

        $shipmentId = $data['shipment_id'] ?? null;
        $trackingNumber = $data['tracking_number'] ?? null;
        $shipment = null;

        if ($trackingNumber) {
            $shipment = Shipment::where('tracking_number', $trackingNumber)->first();
            if ($shipment) {
                $shipmentId = $shipment->id;
            }
        } elseif ($shipmentId) {
            $shipment = Shipment::find($shipmentId);
            if ($shipment) {
                $trackingNumber = $shipment->tracking_number;
            }
        }

        // If no shipment was explicitly passed, find the active out_for_delivery assignment for this rider
        if (!$shipment) {
            $activeAssignment = DeliveryAssignment::query()
                ->where('rider_id', $user->id)
                ->where('status', 'out_for_delivery')
                ->with('shipment')
                ->latest('id')
                ->first();

            if ($activeAssignment && $activeAssignment->shipment) {
                $shipment = $activeAssignment->shipment;
                $shipmentId = $shipment->id;
                $trackingNumber = $shipment->tracking_number;
            }
        }

        $nowIso = now()->toISOString();
        $ttlSeconds = 600; // 10 minutes

        $riderPayload = [
            'rider_id' => (string) $user->id,
            'rider_name' => (string) $user->name,
            'lat' => (string) $data['lat'],
            'lng' => (string) $data['lng'],
            'shipment_id' => (string) ($shipmentId ?? ''),
            'tracking_number' => (string) ($trackingNumber ?? ''),
            'heading' => (string) ($data['heading'] ?? ''),
            'speed' => (string) ($data['speed'] ?? ''),
            'updated_at' => $nowIso,
        ];

        // 1. Cache rider location in Laravel Cache & Redis
        $riderKey = "rider:{$user->id}:location";
        Cache::put($riderKey, $riderPayload, $ttlSeconds);

        try {
            Redis::hmset($riderKey, $riderPayload);
            Redis::expire($riderKey, $ttlSeconds);
        } catch (\Throwable $e) {
            Log::debug('Redis rider location save skipped: ' . $e->getMessage());
        }

        // 2. Cache shipment-level location in Laravel Cache & Redis if shipment exists
        if ($shipment && $trackingNumber) {
            $shipmentPayload = [
                'lat' => (string) $data['lat'],
                'lng' => (string) $data['lng'],
                'heading' => (string) ($data['heading'] ?? ''),
                'speed' => (string) ($data['speed'] ?? ''),
                'rider_id' => (string) $user->id,
                'rider_name' => (string) $user->name,
                'rider_phone' => (string) ($user->phone ?? ''),
                'tracking_number' => (string) $trackingNumber,
                'shipment_id' => (string) $shipmentId,
                'status' => (string) $shipment->status,
                'updated_at' => $nowIso,
            ];

            $shipmentKey = "shipment:{$trackingNumber}:location";
            Cache::put($shipmentKey, $shipmentPayload, $ttlSeconds);

            try {
                Redis::hmset($shipmentKey, $shipmentPayload);
                Redis::expire($shipmentKey, $ttlSeconds);
            } catch (\Throwable $e) {
                Log::debug('Redis shipment location save skipped: ' . $e->getMessage());
            }
        }

        // 3. Fire real-time broadcast event
        event(new RiderLocationUpdated(
            riderId: $user->id,
            lat: (float) $data['lat'],
            lng: (float) $data['lng'],
            shipmentId: $shipmentId ? (int) $shipmentId : null,
            heading: isset($data['heading']) ? (float) $data['heading'] : null,
            speed: isset($data['speed']) ? (float) $data['speed'] : null,
            trackingNumber: $trackingNumber,
            riderName: $user->name,
        ));

        return response()->json([
            'message' => 'Location updated.',
            'data' => [
                'rider_id' => $user->id,
                'lat' => (float) $data['lat'],
                'lng' => (float) $data['lng'],
                'heading' => isset($data['heading']) ? (float) $data['heading'] : null,
                'speed' => isset($data['speed']) ? (float) $data['speed'] : null,
                'shipment_id' => $shipmentId ? (int) $shipmentId : null,
                'tracking_number' => $trackingNumber,
                'broadcast_channel' => $trackingNumber ? "tracking.{$trackingNumber}" : null,
                'updated_at' => $nowIso,
            ],
        ]);
    }
}
