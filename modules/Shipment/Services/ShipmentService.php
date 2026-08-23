<?php
namespace Modules\Shipment\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Merchant\Models\Merchant;
use Modules\Shipment\Models\Shipment;

class ShipmentService
{
    public function __construct(
        private readonly MerchantPickupLocationResolver $pickupResolver,
        private readonly BranchAssignmentService $branchAssignment,
        private readonly ShipmentNumberService $shipmentNumberService,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | External Gateway Shipment
    |--------------------------------------------------------------------------
    */

    public function createFromGateway(
        int $merchantId,
        array $data
    ): Shipment {

        $merchant = Merchant::query()
            ->find($merchantId);

        if (! $merchant) {

            throw ValidationException::withMessages([
                'merchant' =>
                'Authenticated merchant was not found.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Merchant must be active
        |--------------------------------------------------------------------------
        */

        if ($merchant->status !== 'active') {

            throw ValidationException::withMessages([
                'merchant' =>
                'Merchant account is not active.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Create shipment
        |--------------------------------------------------------------------------
        */

        return DB::transaction(function () use (
            $merchant,
            $data
        ) {

            /*
            |--------------------------------------------------------------------------
            | Prevent duplicate merchant order
            |--------------------------------------------------------------------------
            */

            $existing = Shipment::query()
                ->where(
                    'merchant_id',
                    $merchant->id
                )
                ->where(
                    'merchant_order_id',
                    $data['merchant_order_id']
                )
                ->first();

            if ($existing) {
                return $existing;
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
            | Origin branch
            |--------------------------------------------------------------------------
            */

            $origin =
            $this->branchAssignment->resolveOrigin(
                $merchant,
                $pickupLocation
            );

            /*
            |--------------------------------------------------------------------------
            | Destination branch
            |--------------------------------------------------------------------------
            */

            $destination =
            $this->branchAssignment->resolveDestination([
                'latitude'  =>
                $data['delivery_lat'],

                'longitude' =>
                $data['delivery_lng'],

                'city'      =>
                $data['customer_city'] ?? null,

                'area'      =>
                $data['customer_area'] ?? null,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Validate routing
            |--------------------------------------------------------------------------
            */

            if (! $origin['branch_id']) {

                throw ValidationException::withMessages([
                    'pickup_location_id' =>
                    'Unable to determine origin branch.',
                ]);
            }

            if (! $destination['branch_id']) {

                throw ValidationException::withMessages([
                    'delivery_lat' =>
                    'Unable to determine destination branch.',
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Route
            |--------------------------------------------------------------------------
            */

            $route =
            $this->branchAssignment->buildRoute(
                $origin,
                $destination
            );

            /*
            |--------------------------------------------------------------------------
            | Tracking number
            |--------------------------------------------------------------------------
            */

            $trackingNumber =
            $this->shipmentNumberService->generate();

            /*
            |--------------------------------------------------------------------------
            | Shipment payload
            |--------------------------------------------------------------------------
            */

            $shipmentData = [
                'tracking_number'           => $this->generateTrackingNumber(),

                'merchant_id'               => $merchant->id,
                'merchant_order_id'         => $data['merchant_order_id'],
                'order_source'              => 'store_manager',

                'pickup_location_id'        => $pickupLocation->id,

                'pickup_lat'                => $pickupCoordinates['lat'],
                'pickup_lng'                => $pickupCoordinates['lng'],

                'origin_branch_id'          => $origin['branch_id'],
                'origin_sub_branch_id'      => $origin['sub_branch_id'],

                'receiver_name'             => $data['receiver_name'],
                'receiver_phone'            => $data['receiver_phone'],
                'receiver_email'            => $data['receiver_email'] ?? null,

                'delivery_address'          => $data['delivery_address'],
                'delivery_city'             => $data['delivery_city'] ?? null,
                'delivery_area'             => $data['delivery_area'] ?? null,

                'delivery_lat'              => $data['delivery_lat'],
                'delivery_lng'              => $data['delivery_lng'],

                'destination_branch_id'     => $destination['branch_id'],
                'destination_sub_branch_id' => $destination['sub_branch_id'],

                'service_type'              => $data['service_type'],

                'parcel_type'               => $packet['parcel_type'],
                'product_description'       => $packet['description'] ?? null,
                'quantity'                  => $packet['quantity'],
                'weight'                    => $packet['weight'],
                'declared_value'            => $packet['declared_value'],
                'fragile'                   => $packet['fragile'],

                'payment_type'              => $data['payment_type'],
                'delivery_charge_paid_by'   => $data['delivery_charge_paid_by'],

                'self_drop'                 => $data['self_drop'] ?? null,

                'special_instructions'      => $data['special_instructions'] ?? null,
                'remarks'                   => $data['remarks'] ?? null,

                'status'                    => CourierStatus::AWAITING_PICKUP,
            ];

            /*
            |--------------------------------------------------------------------------
            | Only insert columns that actually exist
            |--------------------------------------------------------------------------
            */

            $shipmentData =
            $this->filterShipmentColumns(
                $shipmentData
            );

            /*
            |--------------------------------------------------------------------------
            | Create shipment
            |--------------------------------------------------------------------------
            */

            $shipment = Shipment::query()
                ->create($shipmentData);

            /*
            |--------------------------------------------------------------------------
            | Initial tracking event
            |--------------------------------------------------------------------------
            */

            $this->createTrackingEvent(
                $shipment,
                null,
                'awaiting_pickup',
                'Shipment created successfully. Awaiting pickup.'
            );

            return $shipment->fresh();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Tracking event
    |--------------------------------------------------------------------------
    */

    private function createTrackingEvent(
        Shipment $shipment,
        ?string $oldStatus,
        string $newStatus,
        string $description
    ): void {

        $table = 'shipment_tracking_events';

        if (! DB::getSchemaBuilder()->hasTable($table)) {
            return;
        }

        $data = [
            'shipment_id' =>
            $shipment->id,

            'old_status'  =>
            $oldStatus,

            'status'      =>
            $newStatus,

            'description' =>
            $description,

            'location'    =>
            null,

            'meta_json'   =>
            json_encode([]),

            'created_at'  =>
            now(),

            'updated_at'  =>
            now(),
        ];

        $columns =
        DB::getSchemaBuilder()
            ->getColumnListing($table);

        $data = array_intersect_key(
            $data,
            array_flip($columns)
        );

        DB::table($table)->insert($data);
    }

    /*
    |--------------------------------------------------------------------------
    | Shipment columns
    |--------------------------------------------------------------------------
    */

    private function filterShipmentColumns(
        array $data
    ): array {

        $columns =
        DB::getSchemaBuilder()
            ->getColumnListing('shipments');

        return array_intersect_key(
            $data,
            array_flip($columns)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Status update
    |--------------------------------------------------------------------------
    */

    public function updateStatus(
        Shipment $shipment,
        string $status,
        ?int $userId = null,
        ?string $note = null
    ): Shipment {

        return DB::transaction(function () use (
            $shipment,
            $status,
            $userId,
            $note
        ) {

            $oldStatus =
            $shipment->status;

            $shipment->status =
                $status;

            $shipment->save();

            $this->createTrackingEvent(
                $shipment,
                $oldStatus,
                $status,
                $note ?? 'Shipment status updated.'
            );

            return $shipment->fresh();
        });
    }
}
