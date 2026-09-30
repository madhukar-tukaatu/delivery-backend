<?php

declare(strict_types=1);

namespace Modules\Shipment\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Rate\Services\PricingEngineService;
use Modules\Shipment\Models\Shipment;
use Throwable;

/**
 * Resolve the checkout delivery fee merchants owe when they cover delivery.
 *
 * Prefer the live PricingEngine (branch_route_rates + weight/distance rules).
 * Prefer PricingEngine final_price, else explicit payload delivery_charge from quote.
 * Never fall back to legacy config base_fee (80); fail loudly if charge cannot be resolved.
 */
final class CheckoutDeliveryChargeResolver
{
    public function __construct(
        private readonly PricingEngineService $pricingEngine,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data  Create/quote payload
     * @param  array{lat?: float|null, lng?: float|null}  $pickupCoordinates
     * @return array{
     *     delivery_charge: float,
     *     source: string,
     *     breakdown: ?array,
     *     pod_charge: float
     * }
     */
    public function resolveFromPayload(array $data, array $pickupCoordinates = []): array
    {
        $pickupLat = $this->floatOrNull(
            $pickupCoordinates['lat']
                ?? $data['pickup_lat']
                ?? $data['pickup_latitude']
                ?? null
        );
        $pickupLng = $this->floatOrNull(
            $pickupCoordinates['lng']
                ?? $data['pickup_lng']
                ?? $data['pickup_longitude']
                ?? null
        );
        $deliveryLat = $this->floatOrNull(
            $data['delivery_lat']
                ?? $data['delivery_latitude']
                ?? data_get($data, 'delivery.latitude')
                ?? null
        );
        $deliveryLng = $this->floatOrNull(
            $data['delivery_lng']
                ?? $data['delivery_longitude']
                ?? data_get($data, 'delivery.longitude')
                ?? null
        );

        $weight = $this->resolveWeight($data);
        $serviceType = (string) ($data['service_type'] ?? data_get($data, 'service_type.code') ?? 'standard');
        $parcelType = (string) ($data['parcel_type'] ?? data_get($data, 'packet.parcel_type') ?? 'non_fragile');
        $fragile = filter_var(
            $data['fragile'] ?? data_get($data, 'packet.fragile') ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        if ($fragile && $parcelType === 'non_fragile') {
            $parcelType = 'fragile';
        }

        $explicitCharge = isset($data['delivery_charge'])
            ? round((float) $data['delivery_charge'], 2)
            : null;

        if ($pickupLat !== null && $pickupLng !== null && $deliveryLat !== null && $deliveryLng !== null) {
            try {
                $engine = $this->pricingEngine->calculate([
                    'pickup_latitude' => $pickupLat,
                    'pickup_longitude' => $pickupLng,
                    'delivery_latitude' => $deliveryLat,
                    'delivery_longitude' => $deliveryLng,
                    'service_type' => $serviceType,
                    'parcel_weight' => max($weight, 0.1),
                    'weight' => max($weight, 0.1),
                    'parcel_type' => $parcelType,
                    'payment_type' => $data['payment_type'] ?? 'prepaid',
                    'pod_amount' => (float) (
                        $data['pod_amount']
                            ?? data_get($data, 'payment.pod_amount')
                            ?? 0
                    ),
                ], isset($data['merchant_id']) ? (int) $data['merchant_id'] : null);

                $final = round((float) ($engine['final_price'] ?? 0), 2);
                if ($final > 0) {
                    return [
                        'delivery_charge' => $final,
                        'source' => 'pricing_engine',
                        'breakdown' => $engine,
                        'pod_charge' => round((float) (
                            $data['pod_charge']
                                ?? data_get($engine, 'breakdown.pod_fee.total')
                                ?? data_get($engine, 'pod_fee')
                                ?? 0
                        ), 2),
                    ];
                }
            } catch (Throwable $e) {
                Log::warning('checkout_delivery_charge.pricing_engine_failed', [
                    'error' => $e->getMessage(),
                    'pickup_lat' => $pickupLat,
                    'pickup_lng' => $pickupLng,
                    'delivery_lat' => $deliveryLat,
                    'delivery_lng' => $deliveryLng,
                ]);
            }
        }

        if ($explicitCharge !== null && $explicitCharge > 0) {
            return [
                'delivery_charge' => $explicitCharge,
                'source' => 'payload',
                'breakdown' => is_array($data['delivery_charge_breakdown'] ?? null)
                    ? $data['delivery_charge_breakdown']
                    : null,
                'pod_charge' => round((float) ($data['pod_charge'] ?? 0), 2),
            ];
        }

        // Never silently bill legacy config base_fee (80). Fail loud so create
        // cannot store a flat fallback; callers must supply a quote charge or
        // PricingEngine must resolve from coordinates.
        Log::error('checkout_delivery_charge.unresolvable', [
            'has_coords' => $pickupLat !== null && $deliveryLat !== null,
            'explicit_charge' => $explicitCharge,
            'merchant_id' => $data['merchant_id'] ?? null,
        ]);

        throw ValidationException::withMessages([
            'delivery_charge' => [
                'Unable to resolve delivery charge from PricingEngine or quote payload. '
                . 'Recalculate fare with pickup/delivery coordinates, or pass delivery_charge from a valid quote. '
                . 'Flat config base_fee fallback is disabled.',
            ],
        ]);
    }

    /**
     * Recalculate from a persisted shipment (reconcile / billing repair).
     *
     * @return array{
     *     delivery_charge: float,
     *     source: string,
     *     breakdown: ?array,
     *     pod_charge: float
     * }
     */
    public function resolveFromShipment(Shipment $shipment): array
    {
        return $this->resolveFromPayload([
            'pickup_lat' => $shipment->pickup_lat,
            'pickup_lng' => $shipment->pickup_lng,
            'delivery_lat' => $shipment->delivery_lat,
            'delivery_lng' => $shipment->delivery_lng,
            'weight' => $shipment->chargeable_weight > 0
                ? $shipment->chargeable_weight
                : ($shipment->actual_weight > 0 ? $shipment->actual_weight : $shipment->weight),
            'service_type' => $shipment->service_type ?? 'standard',
            'parcel_type' => $shipment->parcel_type ?? 'non_fragile',
            'fragile' => (bool) ($shipment->fragile ?? false),
            'payment_type' => $shipment->payment_type,
            'pod_amount' => $shipment->pod_amount,
            'pod_charge' => $shipment->pod_charge,
            'merchant_id' => $shipment->merchant_id,
            // Do not prefer the (possibly flat-80) stored charge when reconciling.
        ]);
    }

    private function resolveWeight(array $data): float
    {
        $candidates = [
            data_get($data, 'packet.weight'),
            data_get($data, 'package.weight'),
            $data['weight'] ?? null,
            $data['parcel_weight'] ?? null,
            $data['package_weight'] ?? null,
            $data['chargeable_weight'] ?? null,
            $data['actual_weight'] ?? null,
        ];

        foreach ($candidates as $value) {
            if ($value !== null && (float) $value > 0) {
                return (float) $value;
            }
        }

        return 0.1;
    }

    private function floatOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}
