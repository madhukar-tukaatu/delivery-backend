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

        $roles = method_exists($user, 'roleNames') ? $user->roleNames() : (array) ($user->role ?? []);
        $isRider = in_array('rider', $roles)
            || in_array('delivery_rider', $roles)
            || in_array('delivery-rider', $roles)
            || ($user->role === 'rider')
            || (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
            || in_array('super_admin', $roles)
            || in_array('super-admin', $roles)
            || in_array('admin', $roles);

        if (!$isRider) {
            abort(403, 'Only riders can update live location.');
        }

        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'shipment_id' => ['nullable', 'integer'],
            'tracking_number' => ['nullable', 'string', 'max:191'],
            'run_id' => ['nullable', 'integer'],
            'heading' => ['nullable', 'numeric'],
            'speed' => ['nullable', 'numeric'],
        ]);

        $shipmentId = $data['shipment_id'] ?? null;
        $trackingNumber = $data['tracking_number'] ?? null;
        $runId = $data['run_id'] ?? null;
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

        // If no explicit run was passed, find rider's active out_for_delivery or in_progress delivery run
        $activeRun = null;
        if ($runId) {
            $activeRun = \Modules\Delivery\Models\DeliveryRun::with(['items.shipment'])->find($runId);
        } else {
            $activeRun = \Modules\Delivery\Models\DeliveryRun::query()
                ->where('rider_user_id', $user->id)
                ->whereIn('status', ['out_for_delivery', 'in_progress'])
                ->with(['items.shipment'])
                ->latest('id')
                ->first();
            if ($activeRun) {
                $runId = $activeRun->id;
            }
        }

        // If no shipment was explicitly passed, get it from active run items or legacy assignment
        if (!$shipment && $activeRun && $activeRun->items) {
            $activeItem = $activeRun->items->first(function ($it) {
                return $it->shipment && in_array($it->status, ['out_for_delivery', 'assigned', 'pending']);
            }) ?? $activeRun->items->first();

            if ($activeItem && $activeItem->shipment) {
                $shipment = $activeItem->shipment;
                $shipmentId = $shipment->id;
                $trackingNumber = $shipment->tracking_number;
            }
        }

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
        $ttlSeconds = 900; // 15 minutes

        $riderPayload = [
            'rider_id' => (string) $user->id,
            'rider_name' => (string) $user->name,
            'run_id' => (string) ($runId ?? ''),
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

        // 1b. Cache run-level location if runId is known
        if ($runId) {
            $runKey = "run:{$runId}:location";
            Cache::put($runKey, $riderPayload, $ttlSeconds);
            try {
                Redis::hmset($runKey, $riderPayload);
                Redis::expire($runKey, $ttlSeconds);
            } catch (\Throwable $e) {}
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
            runId: $runId ? (int) $runId : null,
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
