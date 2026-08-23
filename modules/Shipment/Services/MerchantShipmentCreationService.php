<?php

namespace Modules\Shipment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Merchant\Models\Merchant;
use Modules\Shipment\Enums\ShipmentStatus;
use Modules\Shipment\Models\Shipment;
use Modules\Tracking\Services\TrackingService;

class MerchantShipmentCreationService
{
    public function __construct(
        private readonly MerchantShipmentGateService $gate,
        private readonly MerchantPickupLocationResolver $pickupResolver,
        private readonly BranchAssignmentService $branchAssignment,
        private readonly TrackingNumberService $trackingNumbers,
        private readonly TrackingService $trackingService,
    ) {}

    public function create(
        Merchant $merchant,
        array $data,
        ?int $createdBy = null
    ): Shipment {

        $this->gate->ensureCanCreateShipment(
            $merchant
        );

        return DB::transaction(function () use (
            $merchant,
            $data,
            $createdBy
        ) {

            /*
            |--------------------------------------------------------------------------
            | Prevent duplicate Store orders
            |--------------------------------------------------------------------------
            */

            $existing = Shipment::query()
                ->where('merchant_id', $merchant->id)
                ->where(
                    'external_order_id',
                    $data['external_order_id']
                )
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing->load([
                    'merchant',
                    'originBranch',
                    'originSubBranch',
                    'destinationBranch',
                    'destinationSubBranch',
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Pickup location
            |--------------------------------------------------------------------------
            */

            $pickupLocation =
                $this->pickupResolver->resolve(
                    $merchant,
                    $data
                );

            /*
            |--------------------------------------------------------------------------
            | Resolve origin
            |--------------------------------------------------------------------------
            */

            $origin =
                $this->branchAssignment->resolveOrigin(
                    $merchant,
                    $pickupLocation
                );

            /*
            |--------------------------------------------------------------------------
            | Resolve destination
            |--------------------------------------------------------------------------
            */

            $destination =
                $this->branchAssignment->resolveDestination([
                    'latitude' =>
                        $data['delivery_lat'],

                    'longitude' =>
                        $data['delivery_lng'],

                    'city' =>
                        $data['delivery_city'] ?? null,

                    'area' =>
                        $data['delivery_area'] ?? null,
                ]);

            /*
            |--------------------------------------------------------------------------
            | Generate tracking number
            |--------------------------------------------------------------------------
            */

            $trackingNumber =
                $this->trackingNumbers->generate();

            /*
            |--------------------------------------------------------------------------
            | Create shipment
            |--------------------------------------------------------------------------
            */

            $shipment = Shipment::create([

                'merchant_id' =>
                    $merchant->id,

                'external_order_id' =>
                    $data['external_order_id'],

                'external_order_reference' =>
                    $data['external_order_reference'] ?? null,

                'tracking_number' =>
                    $trackingNumber,

                'status' =>
                    ShipmentStatus::AWAITING_PICKUP,

                'merchant_status' =>
                    ShipmentStatus::AWAITING_PICKUP,

                /*
                 * Pickup
                 */
                'pickup_location_id' =>
                    $pickupLocation?->id,

                'sender_name' =>
                    $data['pickup_name']
                    ?? $pickupLocation?->name
                    ?? $merchant->name,

                'sender_phone' =>
                    $data['pickup_phone']
                    ?? $pickupLocation?->phone
                    ?? $merchant->phone,

                'sender_address' =>
                    $data['pickup_address']
                    ?? $pickupLocation?->address
                    ?? $merchant->address,

                'sender_city' =>
                    $data['pickup_city']
                    ?? $pickupLocation?->city
                    ?? $merchant->city,

                'sender_area' =>
                    $data['pickup_area']
                    ?? $pickupLocation?->area
                    ?? $merchant->area,

                'pickup_lat' =>
                    $data['pickup_lat']
                    ?? $pickupLocation?->latitude
                    ?? $merchant->pickup_lat,

                'pickup_lng' =>
                    $data['pickup_lng']
                    ?? $pickupLocation?->longitude
                    ?? $merchant->pickup_lng,

                /*
                 * Origin
                 */
                'origin_branch_id' =>
                    $origin['branch_id'],

                'origin_sub_branch_id' =>
                    $origin['sub_branch_id'],

                /*
                 * Destination
                 */
                'receiver_name' =>
                    $data['receiver_name'],

                'receiver_phone' =>
                    $data['receiver_phone'],

                'delivery_address' =>
                    $data['delivery_address'],

                'delivery_city' =>
                    $data['delivery_city'] ?? null,

                'delivery_area' =>
                    $data['delivery_area'] ?? null,

                'delivery_lat' =>
                    $data['delivery_lat'],

                'delivery_lng' =>
                    $data['delivery_lng'],

                'destination_branch_id' =>
                    $destination['branch_id'],

                'destination_sub_branch_id' =>
                    $destination['sub_branch_id'],

                /*
                 * Parcel
                 */
                'service_type' =>
                    $data['service_type'],

                'parcel_type' =>
                    $data['parcel_type'] ?? 'non_fragile',

                'actual_weight_kg' =>
                    $data['actual_weight_kg'] ?? null,

                'declared_value' =>
                    $data['declared_value'] ?? null,

                'fragile' =>
                    $data['fragile'] ?? false,

                'remarks' =>
                    $data['remarks'] ?? null,

                /*
                 * Current location
                 */
                'current_branch_id' =>
                    $origin['branch_id'],

                'current_sub_branch_id' =>
                    $origin['sub_branch_id'],
            ]);

            /*
            |--------------------------------------------------------------------------
            | Tracking event
            |--------------------------------------------------------------------------
            */

            $this->trackingService->record(
                $shipment,
                ShipmentStatus::AWAITING_PICKUP,
                'Shipment created and is awaiting pickup.',
                $createdBy
            );

            return $shipment->fresh([
                'merchant',
                'originBranch',
                'originSubBranch',
                'destinationBranch',
                'destinationSubBranch',
            ]);
        });
    }
}