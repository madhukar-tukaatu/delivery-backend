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
use Modules\Setting\Models\Marketplace;
use Modules\Shipment\Models\Shipment;

class MarketplaceTrackingController extends Controller
{
    /**
     * Get live tracking details for a shipment by tracking number.
     * Authenticated via Marketplace HMAC Key.
     */
    public function show(Request $request, string $trackingNumber): JsonResponse
    {
        $cleanNumber = trim($trackingNumber);
        $marketplaceId = (int) $request->attributes->get('marketplace_id');

        $shipment = $this->resolveShipmentQuery($marketplaceId)
            ->where('tracking_number', $cleanNumber)
            ->first();

        if (!$shipment) {
            return ApiResponse::error('Shipment not found or does not belong to this marketplace.', 404);
        }

        return ApiResponse::success($this->formatShipmentPayload($shipment));
    }

    /**
     * Get live tracking details using the marketplace's own Order ID.
     * Authenticated via Marketplace HMAC Key.
     */
    public function byOrder(Request $request, string $orderId): JsonResponse
    {
        $cleanOrderId = trim($orderId);
        $marketplaceId = (int) $request->attributes->get('marketplace_id');

        $shipment = $this->resolveShipmentQuery($marketplaceId)
            ->where('merchant_order_id', $cleanOrderId)
            ->first();

        if (!$shipment) {
            return ApiResponse::error('Order not found or does not belong to this marketplace.', 404);
        }

        return ApiResponse::success($this->formatShipmentPayload($shipment));
    }

    /**
     * Ultra-fast polling endpoint for real-time rider location coordinates.
     */
    public function live(Request $request, string $trackingNumber): JsonResponse
    {
        $cleanNumber = trim($trackingNumber);
        $marketplaceId = (int) $request->attributes->get('marketplace_id');

        $shipment = $this->resolveShipmentQuery($marketplaceId)
            ->where('tracking_number', $cleanNumber)
            ->select(['id', 'tracking_number', 'merchant_order_id', 'status'])
            ->first();

        if (!$shipment) {
            return ApiResponse::error('Shipment not found.', 404);
        }

        $cached = Cache::get("shipment:{$cleanNumber}:location");
        if ($cached && is_array($cached) && !empty($cached['lat']) && !empty($cached['lng'])) {
            return ApiResponse::success([
                'tracking_number' => $cleanNumber,
                'merchant_order_id' => $shipment->merchant_order_id,
                'status' => $cached['status'] ?? $shipment->status,
                'is_live' => true,
                'live_location' => [
                    'lat' => (float) $cached['lat'],
                    'lng' => (float) $cached['lng'],
                    'heading' => isset($cached['heading']) && is_numeric($cached['heading']) ? (float) $cached['heading'] : null,
                    'speed_kmh' => isset($cached['speed']) && is_numeric($cached['speed']) ? (float) $cached['speed'] : null,
                    'updated_at' => $cached['updated_at'] ?? now()->toIso8601String(),
                ],
                'rider' => [
                    'name' => $cached['rider_name'] ?? null,
                ],
            ]);
        }

        return ApiResponse::success([
            'tracking_number' => $cleanNumber,
            'merchant_order_id' => $shipment->merchant_order_id,
            'status' => $shipment->status,
            'is_live' => false,
            'live_location' => null,
        ]);
    }

    /**
     * Build base query scoped to this marketplace.
     */
    private function resolveShipmentQuery(int $marketplaceId)
    {
        $query = Shipment::query()
            ->with([
                'merchant:id,name,marketplace_id',
                'originBranch:id,name,city,latitude,longitude,office_latitude,office_longitude',
                'destinationBranch:id,name,city,latitude,longitude,office_latitude,office_longitude',
                'currentBranch:id,name,city,latitude,longitude,office_latitude,office_longitude',
                'trackingEvents' => fn ($q) => $q->whereIn('visibility', ['public', 'merchant'])->oldest(),
            ]);

        if ($marketplaceId > 0) {
            $query->where(function ($q) use ($marketplaceId) {
                $q->where('shipments.marketplace_id', $marketplaceId)
                  ->orWhereHas('merchant', function ($mq) use ($marketplaceId) {
                      $mq->where('marketplace_id', $marketplaceId);
                  });
            });
        }

        return $query;
    }

    /**
     * Format full structured payload for marketplace consumers.
     */
    private function formatShipmentPayload(Shipment $shipment): array
    {
        $statusLower = strtolower((string) ($shipment->status ?? ''));
        $isOutForDelivery = in_array($statusLower, ['out_for_delivery', 'arrived'], true);
        $isDelivered = $statusLower === 'delivered';

        $activeAssignment = DeliveryAssignment::query()
            ->where('shipment_id', $shipment->id)
            ->with(['rider:id,name,phone'])
            ->latest('id')
            ->first();

        $liveLocation = null;
        if ($isOutForDelivery) {
            $cached = Cache::get("shipment:{$shipment->tracking_number}:location");
            if (!$cached && $activeAssignment?->rider_id) {
                $cached = Cache::get("rider:{$activeAssignment->rider_id}:location");
            }
            if ($cached && is_array($cached) && !empty($cached['lat']) && !empty($cached['lng'])) {
                $liveLocation = [
                    'lat' => (float) $cached['lat'],
                    'lng' => (float) $cached['lng'],
                    'heading' => isset($cached['heading']) && is_numeric($cached['heading']) ? (float) $cached['heading'] : null,
                    'speed_kmh' => isset($cached['speed']) && is_numeric($cached['speed']) ? (float) $cached['speed'] : null,
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
                'occurred_at' => $event->created_at?->toIso8601String(),
            ];
        });

        return [
            'tracking_number' => $shipment->tracking_number,
            'merchant_order_id' => $shipment->merchant_order_id,
            'order_source' => $shipment->order_source,
            'status' => $shipment->status,
            'merchant_status' => CourierStatus::merchantStatus((string) $shipment->status),
            'is_out_for_delivery' => $isOutForDelivery,
            'is_delivered' => $isDelivered,
            'destination' => [
                'recipient_name' => $shipment->receiver_name,
                'city' => $shipment->receiver_city,
                'area' => $shipment->receiver_area,
                'address' => $shipment->delivery_address ?: $shipment->receiver_address,
                'latitude' => $destLat,
                'longitude' => $destLng,
            ],
            'origin' => [
                'branch_name' => $shipment->originBranch?->name ?: 'Origin Hub',
                'city' => $shipment->originBranch?->city,
                'latitude' => $originLat,
                'longitude' => $originLng,
            ],
            'rider' => ($isOutForDelivery || $isDelivered) && $activeAssignment?->rider ? [
                'name' => $activeAssignment->rider->name,
                'phone' => $isOutForDelivery ? $activeAssignment->rider->phone : null,
            ] : null,
            'live_location' => $liveLocation,
            'tracking_url' => url("/track/{$shipment->tracking_number}"),
            'websocket_channel' => "tracking.{$shipment->tracking_number}",
            'events' => $events,
        ];
    }
}
