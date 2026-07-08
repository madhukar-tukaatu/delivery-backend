<?php

namespace Modules\Routing\Services;

class TariffCalculationService
{
    public function calculate(array $steps, float $weight = 1, float $codAmount = 0): array
    {
        $routeDistance = array_sum(array_column($steps, 'distance_km'));
        $routeFee = array_sum(array_column($steps, 'fee'));
        $estimatedHours = array_sum(array_column($steps, 'estimated_hours'));

        $pickupFee = 50;
        $deliveryFee = 60;
        $weightFee = max(0, ceil($weight - 1)) * 25;
        $codFee = $codAmount > 0 ? max(10, round($codAmount * 0.01, 2)) : 0;
        $remoteAreaFee = $routeDistance > 250 ? 100 : 0;

        $total = $pickupFee + $deliveryFee + $routeFee + $weightFee + $codFee + $remoteAreaFee;

        return [
            'delivery_charge' => round($total, 2),
            'cod_charge' => round($codFee, 2),
            'route_distance_km' => round($routeDistance, 2),
            'route_fee' => round($routeFee, 2),
            'estimated_delivery_time' => $estimatedHours <= 24 ? 'Same day / next day' : ceil($estimatedHours / 24) . ' days',
            'breakdown' => [
                'pickup_fee' => round($pickupFee, 2),
                'delivery_fee' => round($deliveryFee, 2),
                'route_fee' => round($routeFee, 2),
                'weight_fee' => round($weightFee, 2),
                'cod_fee' => round($codFee, 2),
                'remote_area_fee' => round($remoteAreaFee, 2),
                'total' => round($total, 2),
            ],
        ];
    }
}
