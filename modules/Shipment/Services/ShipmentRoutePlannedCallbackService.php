<?php

declare(strict_types=1);

namespace Modules\Shipment\Services;

use Modules\Shipment\Models\Shipment;
use Modules\Pickup\Models\PickupCallbackLog;
use Modules\Rate\Services\ConfiguredTransferRouteService;
use Illuminate\Support\Facades\Log;

final class ShipmentRoutePlannedCallbackService
{
    public function __construct(
        private readonly ConfiguredTransferRouteService $transferRouteResolver,
    ) {}

    /**
     * Build and send shipment.route_planned callback to merchant's integration URL.
     * Includes travel details: origin, transit points, destination, distances, ETAs.
     */
    public function sendRoutePlanned(Shipment $shipment): ?PickupCallbackLog
    {
        $shipment->loadMissing([
            'merchant',
            'originBranch',
            'destinationBranch',
            'pickupLocation',
        ]);

        // Must have route_id (resolved during assignment/creation)
        if (!$shipment->route_id) {
            Log::warning('Shipment route planned: no route_id', ['shipment_id' => $shipment->id]);
            return null;
        }

        // Resolve route to get full travel details (path, distances, transits)
        try {
            $routeDetails = $this->transferRouteResolver->resolve(
                originBranchId: (int) $shipment->origin_branch_id,
                destinationBranchId: (int) $shipment->destination_branch_id,
                serviceType: $shipment->service_type ?? 'standard'
            );
        } catch (\Exception $e) {
            Log::error('Shipment route planned: resolver failed', [
                'shipment_id' => $shipment->id,
                'error'       => $e->getMessage(),
            ]);
            return null;
        }

        // Build callback payload
        $payload = [
            'event'      => 'shipment.route_planned',
            'timestamp'  => now()->toIso8601String(),
            'shipment'   => [
                'id'              => (string) $shipment->id,
                'shipment_number' => $shipment->shipment_number,
                'reference'       => $shipment->merchant_reference ?? $shipment->shipment_number,
                'status'          => $shipment->status,
                'service_type'    => $shipment->service_type,
            ],
            'route' => [
                'route_id'              => $routeDetails['route_id'],
                'route_code'            => $routeDetails['route_code'],
                'route_name'            => $routeDetails['route_name'],
                'path_text'             => $routeDetails['path_text'],
                'total_distance_km'     => $routeDetails['total_distance_km'],
                'total_estimated_hours' => $routeDetails['total_estimated_hours'],
                'transfer_count'        => $routeDetails['transfer_count'],
                'transit_count'         => $routeDetails['transit_count'],
            ],
            'origin' => [
                'branch_id'   => (int) $shipment->origin_branch_id,
                'branch_name' => $shipment->originBranch?->name,
                'branch_code' => $shipment->originBranch?->code,
            ],
            'destination' => [
                'branch_id'   => (int) $shipment->destination_branch_id,
                'branch_name' => $shipment->destinationBranch?->name,
                'branch_code' => $shipment->destinationBranch?->code,
            ],
            'path' => $routeDetails['path'],
            'transit_branches' => $routeDetails['transit_branches'],
        ];

        // Get merchant's callback URL
        $callbackUrl = $shipment->merchant?->integration_callback_url;
        if (!$callbackUrl) {
            Log::warning('Shipment route planned: no callback URL for merchant', [
                'shipment_id' => $shipment->id,
                'merchant_id' => $shipment->merchant_id,
            ]);
            return null;
        }

        // Create callback log
        $callbackLog = PickupCallbackLog::create([
            'shipment_id'  => $shipment->id,
            'merchant_id'  => $shipment->merchant_id,
            'event_type'   => 'shipment.route_planned',
            'payload'      => $payload,
            'callback_url' => $callbackUrl,
            'status'       => PickupCallbackLog::STATUS_PENDING,
        ]);

        return $callbackLog;
    }
}
