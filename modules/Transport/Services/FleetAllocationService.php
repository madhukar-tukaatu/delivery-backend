<?php

namespace Modules\Transport\Services;

use Modules\Transport\Models\TransportVehicle;
use Modules\Rate\Models\BranchTransferRoute;

class FleetAllocationService
{
    /**
     * Recommend the optimal vehicle type based on volume, weight, and distance.
     */
    public function recommendVehicleType(int $parcelCount, float $weightKg = 0.0): array
    {
        if ($parcelCount <= 15 && $weightKg <= 30.0) {
            return [
                'vehicle_type' => TransportVehicle::TYPE_BIKE,
                'label' => 'Motorcycle Courier',
                'reason' => 'Small express load under 15 parcels',
                'typical_capacity_parcels' => 25,
            ];
        }

        if ($parcelCount <= 60 && $weightKg <= 200.0) {
            return [
                'vehicle_type' => TransportVehicle::TYPE_VAN,
                'label' => 'Cargo Van / Maruti Eeco',
                'reason' => 'Light multi-bag transfer (up to 60 parcels)',
                'typical_capacity_parcels' => 80,
            ];
        }

        if ($parcelCount <= 150 && $weightKg <= 750.0) {
            return [
                'vehicle_type' => TransportVehicle::TYPE_PICKUP,
                'label' => 'Pickup / Tata Ace (Chhota Hathi)',
                'reason' => 'Medium corridor freight (60-150 parcels)',
                'typical_capacity_parcels' => 200,
            ];
        }

        if ($parcelCount <= 450 && $weightKg <= 3000.0) {
            return [
                'vehicle_type' => TransportVehicle::TYPE_MINI_TRUCK,
                'label' => '14-Foot Mini Truck (Eicher / Tata 407)',
                'reason' => 'Heavy inter-hub transfer (150-450 parcels)',
                'typical_capacity_parcels' => 500,
            ];
        }

        return [
            'vehicle_type' => TransportVehicle::TYPE_HEAVY_TRUCK,
            'label' => '22-Foot Multi-Axle Line-haul Truck',
            'reason' => 'High-density national highway consolidation (450+ parcels)',
            'typical_capacity_parcels' => 1500,
        ];
    }

    /**
     * Find available vehicles near origin branch matching required capacity.
     */
    public function findAvailableVehicles(int $fromBranchId, string $preferredType = null)
    {
        $query = TransportVehicle::query()
            ->active()
            ->where('status', TransportVehicle::STATUS_AVAILABLE)
            ->where(function ($q) use ($fromBranchId) {
                $q->where('current_branch_id', $fromBranchId)
                    ->orWhereNull('current_branch_id');
            });

        if ($preferredType) {
            $query->orderByRaw("CASE WHEN vehicle_type = ? THEN 0 ELSE 1 END", [$preferredType]);
        }

        return $query->limit(20)->get();
    }

    /**
     * Calculate line-haul trip budget based on distance and vehicle rate.
     */
    public function estimateFreightCost(TransportVehicle $vehicle, float $distanceKm): float
    {
        if ($vehicle->fixed_rate_per_trip && $vehicle->fixed_rate_per_trip > 0) {
            return round($vehicle->fixed_rate_per_trip, 2);
        }

        $ratePerKm = $vehicle->rate_per_km ?? $this->defaultRatePerKm($vehicle->vehicle_type);
        return round($ratePerKm * max(1.0, $distanceKm), 2);
    }

    private function defaultRatePerKm(string $type): float
    {
        return match ($type) {
            TransportVehicle::TYPE_BIKE => 12.0,
            TransportVehicle::TYPE_VAN => 25.0,
            TransportVehicle::TYPE_PICKUP => 35.0,
            TransportVehicle::TYPE_MINI_TRUCK => 55.0,
            TransportVehicle::TYPE_HEAVY_TRUCK => 85.0,
            TransportVehicle::TYPE_BUS_CARGO => 20.0,
            default => 30.0,
        };
    }
}

