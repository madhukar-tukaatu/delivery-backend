<?php

declare(strict_types=1);

namespace Modules\Shipment\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Merchant\Models\Merchant;
use Modules\Shipment\Models\Shipment;
use Modules\Tracking\Services\TrackingService;

final class MerchantShipmentService
{
    public function __construct(
        private readonly MerchantShipmentGateService $merchantGate,
        private readonly MerchantPickupLocationResolver $pickupResolver,
        private readonly BranchAssignmentService $branchAssignment,
        private readonly ShipmentNumberService $shipmentNumberService,
        private readonly TrackingService $trackingService,
        private readonly ShipmentWorkflowService $workflowService,
    ) {
    }

    public function createFromGateway(
        int $merchantId,
        array $data
    ): Shipment {

        return DB::transaction(function () use (
            $merchantId,
            $data
        ) {

            /*
            |--------------------------------------------------------------------------
            | 1. Load merchant
            |--------------------------------------------------------------------------
            */

            $merchant = Merchant::query()
                ->with('pickupLocations')
                ->findOrFail($merchantId);

            /*
            |--------------------------------------------------------------------------
            | 2. Validate merchant
            |--------------------------------------------------------------------------
            */

            $this->merchantGate
                ->ensureCanCreateShipment($merchant);

            /*
            |--------------------------------------------------------------------------
            | 3. Resolve pickup location
            |--------------------------------------------------------------------------
            */

            $pickupLocation =
                $this->pickupResolver->resolve(
                    $merchant,
                    $data
                );

            /*
            |--------------------------------------------------------------------------
            | 4. Resolve origin
            |--------------------------------------------------------------------------
            */

            $origin =
                $this->branchAssignment->resolveOrigin(
                    $merchant,
                    $pickupLocation
                );

            /*
            |--------------------------------------------------------------------------
            | 5. Resolve destination
            |--------------------------------------------------------------------------
            */

            $destination =
                $this->branchAssignment->resolveDestination([
                    'latitude' =>
                        $data['delivery_lat'],

                    'longitude' =>
                        $data['delivery_lng'],

                    'city' =>
                        $data['customer_city'] ?? null,

                    'area' =>
                        $data['customer_area'] ?? null,
                ]);

            /*
            |--------------------------------------------------------------------------
            | 6. Validate branch resolution
            |--------------------------------------------------------------------------
            */

            if (!$origin['branch_id']) {
                throw ValidationException::withMessages([
                    'pickup' =>
                        'Unable to determine the origin branch.',
                ]);
            }

            if (!$destination['branch_id']) {
                throw ValidationException::withMessages([
                    'delivery' =>
                        'Unable to determine the destination branch.',
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | 7. Generate tracking number
            |--------------------------------------------------------------------------
            */

            $trackingNumber =
                $this->shipmentNumberService->generate();

            /*
            |--------------------------------------------------------------------------
            | 8. Create shipment
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            | No price calculation here.
            |
            */

            $shipment = Shipment::create([
                'merchant_id' =>
                    $merchant->id,

                'merchant_order_id' =>
                    $data['merchant_order_id'],

                'tracking_number' =>
                    $trackingNumber,

                'order_source' =>
                    $data['order_source'] ?? 'store_manager',

                /*
                |--------------------------------------------------------------------------
                | Sender
                |--------------------------------------------------------------------------
                */

                'sender_name' =>
                    $pickupLocation?->name
                    ?? $merchant->name,

                'sender_phone' =>
                    $pickupLocation?->phone
                    ?? $merchant->phone,

                'sender_address' =>
                    $pickupLocation?->address
                    ?? $merchant->pickup_address
                    ?? $merchant->address,

                'sender_city' =>
                    $pickupLocation?->city
                    ?? $merchant->pickup_city
                    ?? $merchant->city,

                'sender_area' =>
                    $pickupLocation?->area
                    ?? $merchant->pickup_area
                    ?? $merchant->area,

                'pickup_lat' =>
                    $pickupLocation?->latitude
                    ?? $merchant->pickup_lat,

                'pickup_lng' =>
                    $pickupLocation?->longitude
                    ?? $merchant->pickup_lng,

                /*
                |--------------------------------------------------------------------------
                | Receiver
                |--------------------------------------------------------------------------
                */

                'customer_name' =>
                    $data['customer_name'],

                'customer_phone' =>
                    $data['customer_phone'],

                'customer_email' =>
                    $data['customer_email'] ?? null,

                'customer_address' =>
                    $data['customer_address'],

                'customer_city' =>
                    $data['customer_city'] ?? null,

                'customer_area' =>
                    $data['customer_area'] ?? null,

                'delivery_lat' =>
                    $data['delivery_lat'],

                'delivery_lng' =>
                    $data['delivery_lng'],

                /*
                |--------------------------------------------------------------------------
                | Branches
                |--------------------------------------------------------------------------
                */

                'origin_branch_id' =>
                    $origin['branch_id'],

                'origin_sub_branch_id' =>
                    $origin['sub_branch_id'],

                'destination_branch_id' =>
                    $destination['branch_id'],

                'destination_sub_branch_id' =>
                    $destination['sub_branch_id'],

                'current_branch_id' =>
                    $origin['branch_id'],

                'current_sub_branch_id' =>
                    $origin['sub_branch_id'],

                /*
                |--------------------------------------------------------------------------
                | Parcel
                |--------------------------------------------------------------------------
                */

                'service_type' =>
                    $data['service_type'],

                'parcel_type' =>
                    $data['parcel_type'],

                'product_description' =>
                    $data['product_description'] ?? null,

                'quantity' =>
                    $data['quantity'],

                'weight' =>
                    $data['weight'],

                'declared_value' =>
                    $data['declared_value'],

                'fragile' =>
                    $data['fragile'] ?? false,

                /*
                |--------------------------------------------------------------------------
                | Payment
                |--------------------------------------------------------------------------
                */

                'payment_type' =>
                    $data['payment_type'],

                'delivery_charge_paid_by' =>
                    $data['delivery_charge_paid_by'],

                /*
                |--------------------------------------------------------------------------
                | Instructions
                |--------------------------------------------------------------------------
                */

                'self_drop' =>
                    $data['self_drop'] ?? false,

                'special_instructions' =>
                    $data['special_instructions'] ?? null,

                'remarks' =>
                    $data['remarks'] ?? null,

                /*
                |--------------------------------------------------------------------------
                | Initial status
                |--------------------------------------------------------------------------
                */

                'status' =>
                    'awaiting_pickup',

                'merchant_status' =>
                    'awaiting_pickup',
            ]);

            /*
            |--------------------------------------------------------------------------
            | 9. Shipment items
            |--------------------------------------------------------------------------
            */

            foreach ($data['items'] as $item) {

                $shipment->items()->create([
                    'name' =>
                        $item['name'],

                    'quantity' =>
                        $item['quantity'],

                    'value' =>
                        $item['value'] ?? 0,
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | 10. Tracking event
            |--------------------------------------------------------------------------
            */

            $this->trackingService->record(
                $shipment,
                'awaiting_pickup',
                'Shipment created and waiting for pickup.',
                null
            );

            /*
            |--------------------------------------------------------------------------
            | 11. Create pickup workflow
            |--------------------------------------------------------------------------
            */

            $this->workflowService
                ->createWorkflow($shipment->fresh());

            return $shipment->fresh([
                'merchant',
                'items',
                'originBranch',
                'originSubBranch',
                'destinationBranch',
                'destinationSubBranch',
                'pickupRequest',
            ]);
        });
    }
}