<?php

declare(strict_types=1);

namespace Modules\Tracking\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\CourierStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Delivery\Models\DeliveryAssignment;
use Modules\Shipment\Models\Shipment;

class PublicTrackingController extends Controller
{
    /**
     * Public detailed shipment tracking view.
     */
    public function show(string $trackingNumber): JsonResponse
    {
        $cleanNumber = trim($trackingNumber);

        $shipment = Shipment::query()
            ->where('tracking_number', $cleanNumber)
            ->with([
                'originBranch:id,name,city,latitude,longitude,office_latitude,office_longitude',
                'destinationBranch:id,name,city,latitude,longitude,office_latitude,office_longitude',
                'currentBranch:id,name,city,latitude,longitude,office_latitude,office_longitude',
                'trackingEvents' => fn ($q) => $q->whereIn('visibility', ['public', 'merchant'])->oldest(),
            ])
            ->first();

        if (!$shipment) {
            return ApiResponse::error('Tracking number not found.', 404);
        }

        $statusLower = strtolower((string) ($shipment->status ?? ''));
        $isOutForDelivery = in_array($statusLower, ['out_for_delivery', 'arrived'], true);
        $isDelivered = $statusLower === 'delivered';

        $activeAssignment = DeliveryAssignment::query()
            ->where('shipment_id', $shipment->id)
            ->with(['rider:id,name,phone'])
            ->latest('id')
            ->first();

        // Also resolve rider and run from modern Delivery Run
        $rider = $activeAssignment?->rider;
        $riderId = $activeAssignment?->rider_id;
        $runId = null;

        $runItem = \Modules\Delivery\Models\DeliveryRunItem::query()
            ->where('shipment_id', $shipment->id)
            ->where('status', '!=', \Modules\Delivery\Models\DeliveryRunItem::STATUS_REMOVED)
            ->with(['deliveryRun.rider'])
            ->latest('id')
            ->first();

        if ($runItem && $runItem->deliveryRun) {
            $runId = $runItem->delivery_run_id;
            if (!$rider && $runItem->deliveryRun->rider) {
                $rider = $runItem->deliveryRun->rider;
                $riderId = $runItem->deliveryRun->rider_user_id;
            }
        }

        $liveLocation = null;
        if ($isOutForDelivery) {
            $cached = Cache::get("shipment:{$cleanNumber}:location");
            if (!$cached && $runId) {
                $cached = Cache::get("run:{$runId}:location");
            }
            if (!$cached && $riderId) {
                $cached = Cache::get("rider:{$riderId}:location");
            }
            if ($cached && is_array($cached) && !empty($cached['lat']) && !empty($cached['lng'])) {
                $liveLocation = [
                    'lat' => (float) $cached['lat'],
                    'lng' => (float) $cached['lng'],
                    'heading' => isset($cached['heading']) && is_numeric($cached['heading']) ? (float) $cached['heading'] : null,
                    'speed' => isset($cached['speed']) && is_numeric($cached['speed']) ? (float) $cached['speed'] : null,
                    'updated_at' => $cached['updated_at'] ?? null,
                    'is_live' => true,
                ];
            }
        }

        $originLat = (float) ($shipment->originBranch?->latitude ?? $shipment->originBranch?->office_latitude ?? 0) ?: null;
        $originLng = (float) ($shipment->originBranch?->longitude ?? $shipment->originBranch?->office_longitude ?? 0) ?: null;

        $destLat = (float) ($shipment->delivery_lat ?? $shipment->receiver_latitude ?? 0) ?: null;
        $destLng = (float) ($shipment->delivery_lng ?? $shipment->receiver_longitude ?? 0) ?: null;

        $events = $shipment->trackingEvents->map(function ($event) {
            return [
                'id' => $event->id,
                'status' => $event->status,
                'merchant_status' => $event->merchant_status,
                'description' => $event->description,
                'location' => $event->location_text,
                'created_at' => $event->created_at?->toIso8601String(),
            ];
        });

        return ApiResponse::success([
            'tracking_number' => $shipment->tracking_number,
            'merchant_order_id' => $shipment->merchant_order_id,
            'payment_type' => $shipment->payment_type,
            'collect_amount' => (float) ($shipment->collect_amount ?? $shipment->pod_amount ?? 0),
            'weight' => $shipment->weight ? (float) $shipment->weight : null,
            'status' => CourierStatus::merchantStatus((string) $shipment->status),
            'current_status' => $shipment->status,
            'is_out_for_delivery' => $isOutForDelivery,
            'is_delivered' => $isDelivered,
            'recipient' => [
                'name' => $shipment->receiver_name,
                'city' => $shipment->receiver_city,
                'area' => $shipment->receiver_area,
                'address' => $shipment->delivery_address ?: $shipment->receiver_address,
                'latitude' => $destLat,
                'longitude' => $destLng,
            ],
            'origin' => [
                'name' => $shipment->originBranch?->name ?: 'Origin Hub',
                'city' => $shipment->originBranch?->city,
                'latitude' => $originLat,
                'longitude' => $originLng,
            ],
            'destination' => [
                'name' => $shipment->destinationBranch?->name ?: $shipment->receiver_city,
                'city' => $shipment->receiver_city,
                'latitude' => $destLat,
                'longitude' => $destLng,
            ],
            'current_location' => [
                'name' => $shipment->currentBranch?->name ?: $shipment->receiver_city,
                'city' => $shipment->currentBranch?->city ?: $shipment->receiver_city,
            ],
            'rider' => ($isOutForDelivery || $isDelivered) && $activeAssignment?->rider ? [
                'name' => $activeAssignment->rider->name,
                'phone' => $isOutForDelivery ? $activeAssignment->rider->phone : null,
            ] : null,
            'live_location' => $liveLocation,
            'tracking_url' => url("/track/{$shipment->tracking_number}"),
            'websocket_channel' => "tracking.{$shipment->tracking_number}",
            'events' => $events,
        ]);
    }

    /**
     * Ultra-fast polling endpoint for live rider GPS position.
     */
    public function live(string $trackingNumber): JsonResponse
    {
        $cleanNumber = trim($trackingNumber);

        $cached = Cache::get("shipment:{$cleanNumber}:location");

        if ($cached && is_array($cached) && !empty($cached['lat']) && !empty($cached['lng'])) {
            return ApiResponse::success([
                'tracking_number' => $cleanNumber,
                'status' => $cached['status'] ?? 'out_for_delivery',
                'is_live' => true,
                'live_location' => [
                    'lat' => (float) $cached['lat'],
                    'lng' => (float) $cached['lng'],
                    'heading' => isset($cached['heading']) && is_numeric($cached['heading']) ? (float) $cached['heading'] : null,
                    'speed' => isset($cached['speed']) && is_numeric($cached['speed']) ? (float) $cached['speed'] : null,
                    'updated_at' => $cached['updated_at'] ?? now()->toIso8601String(),
                ],
                'rider' => [
                    'name' => $cached['rider_name'] ?? null,
                ],
            ]);
        }

        $shipment = Shipment::query()
            ->where('tracking_number', $cleanNumber)
            ->select(['id', 'tracking_number', 'status'])
            ->first();

        if (!$shipment) {
            return ApiResponse::error('Shipment not found.', 404);
        }

        $statusLower = strtolower((string) $shipment->status);
        $isOutForDelivery = in_array($statusLower, ['out_for_delivery', 'arrived'], true);

        if ($isOutForDelivery) {
            $runItem = \Modules\Delivery\Models\DeliveryRunItem::query()
                ->where('shipment_id', $shipment->id)
                ->where('status', '!=', \Modules\Delivery\Models\DeliveryRunItem::STATUS_REMOVED)
                ->with(['deliveryRun'])
                ->latest('id')
                ->first();

            $runId = $runItem?->delivery_run_id;
            $riderId = $runItem?->deliveryRun?->rider_user_id;

            if (!$riderId) {
                $activeAssignment = DeliveryAssignment::query()
                    ->where('shipment_id', $shipment->id)
                    ->latest('id')
                    ->first();
                $riderId = $activeAssignment?->rider_id;
            }

            $riderLoc = null;
            if ($runId) {
                $riderLoc = Cache::get("run:{$runId}:location");
            }
            if (!$riderLoc && $riderId) {
                $riderLoc = Cache::get("rider:{$riderId}:location");
            }

            if ($riderLoc && is_array($riderLoc) && !empty($riderLoc['lat']) && !empty($riderLoc['lng'])) {
                return ApiResponse::success([
                    'tracking_number' => $cleanNumber,
                    'status' => $shipment->status,
                    'is_live' => true,
                    'live_location' => [
                        'lat' => (float) $riderLoc['lat'],
                        'lng' => (float) $riderLoc['lng'],
                        'heading' => isset($riderLoc['heading']) && is_numeric($riderLoc['heading']) ? (float) $riderLoc['heading'] : null,
                        'speed' => isset($riderLoc['speed']) && is_numeric($riderLoc['speed']) ? (float) $riderLoc['speed'] : null,
                        'updated_at' => $riderLoc['updated_at'] ?? now()->toIso8601String(),
                    ],
                ]);
            }
        }

        return ApiResponse::success([
            'tracking_number' => $cleanNumber,
            'status' => $shipment->status,
            'is_live' => false,
            'live_location' => null,
        ]);
    }
}
