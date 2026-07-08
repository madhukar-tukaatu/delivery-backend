<?php

namespace Modules\Routing\Services;

use Modules\Routing\Models\ShipmentRouteStep;
use Modules\Shipment\Models\Shipment;

class ShipmentRoutingService
{
    public function __construct(
        private BranchRoutingService $branchRouting,
        private DeliveryRouteService $deliveryRoute,
        private TariffCalculationService $tariff,
    ) {}

    public function quote(array $data): array
    {
        $origin = $this->branchRouting->nearest((float) $data['pickup_lat'], (float) $data['pickup_lng'], 'pickup');
        $destination = $this->branchRouting->nearest((float) $data['delivery_lat'], (float) $data['delivery_lng'], 'delivery');

        $steps = $this->deliveryRoute->buildRoute(
            $origin['sub_branch'],
            $origin['branch'],
            $destination['branch'],
            $destination['sub_branch'],
            (float) ($data['weight'] ?? 1)
        );

        $tariff = $this->tariff->calculate($steps, (float) ($data['weight'] ?? 1), (float) ($data['cod_amount'] ?? 0));

        return [
            'origin' => [
                'branch' => $origin['branch'],
                'sub_branch' => $origin['sub_branch'],
                'distance_to_pickup_km' => $origin['distance_km'],
            ],
            'destination' => [
                'branch' => $destination['branch'],
                'sub_branch' => $destination['sub_branch'],
                'distance_to_delivery_km' => $destination['distance_km'],
            ],
            'steps' => $steps,
            'tariff' => $tariff,
        ];
    }

    public function applyToShipment(Shipment $shipment, array $data): Shipment
    {
        $quote = $this->quote($data);
        $originBranch = $quote['origin']['branch'];
        $originSubBranch = $quote['origin']['sub_branch'];
        $destinationBranch = $quote['destination']['branch'];
        $destinationSubBranch = $quote['destination']['sub_branch'];
        $tariff = $quote['tariff'];

        $shipment->update([
            'pickup_lat' => $data['pickup_lat'],
            'pickup_lng' => $data['pickup_lng'],
            'delivery_lat' => $data['delivery_lat'],
            'delivery_lng' => $data['delivery_lng'],
            'origin_branch_id' => $originBranch?->id,
            'origin_sub_branch_id' => $originSubBranch?->id,
            'destination_branch_id' => $destinationBranch?->id,
            'destination_sub_branch_id' => $destinationSubBranch?->id,
            'current_branch_id' => $originBranch?->id,
            'current_sub_branch_id' => $originSubBranch?->id,
            'route_distance_km' => $tariff['route_distance_km'],
            'route_fee' => $tariff['route_fee'],
            'estimated_delivery_time' => $tariff['estimated_delivery_time'],
            'delivery_charge' => $tariff['delivery_charge'],
            'cod_charge' => $tariff['cod_charge'],
            'delivery_charge_breakdown' => $tariff['breakdown'],
        ]);

        ShipmentRouteStep::where('shipment_id', $shipment->id)->delete();
        foreach ($quote['steps'] as $step) {
            ShipmentRouteStep::create([
                'shipment_id' => $shipment->id,
                'sequence' => $step['sequence'],
                'from_branch_id' => $step['from_branch_id'],
                'to_branch_id' => $step['to_branch_id'],
                'distance_km' => $step['distance_km'],
                'fee' => $step['fee'],
                'estimated_hours' => $step['estimated_hours'],
                'status' => 'pending',
            ]);
        }

        return $shipment->fresh(['routeSteps']);
    }
}
