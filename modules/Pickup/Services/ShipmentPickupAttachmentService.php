<?php

declare(strict_types=1);

namespace Modules\Pickup\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantPickupLocation;
use Modules\Pickup\Models\PickupRequest;
use Modules\Shipment\Models\Shipment;

final class ShipmentPickupAttachmentService
{
    /**
     * Attach a shipment to the merchant's currently active pickup.
     *
     * Workflow:
     *
     * 1. Find an active pickup for this merchant + pickup location.
     * 2. If one exists, attach the shipment to it.
     * 3. If none exists, create a pickup request.
     * 4. Attach the shipment to the new pickup.
     *
     * There is no shipment creation cutoff.
     */
    public function attachShipmentToActivePickup(
        Shipment $shipment,
        Merchant $merchant,
        MerchantPickupLocation $pickupLocation,
    ): void {
        /*
        |--------------------------------------------------------------------------
        | Validate ownership
        |--------------------------------------------------------------------------
        */

        if (
            (int) $pickupLocation->merchant_id !==
            (int) $merchant->id
        ) {
            throw ValidationException::withMessages([
                'pickup_location_id' => [
                    'Pickup location does not belong to this merchant.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Find active pickup
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | We intentionally do NOT use pickup_shipments.
        |
        | Shipments are related to pickups through:
        |
        | pickup_request_shipments
        |
        */

        $pickup = PickupRequest::query()
            ->where('merchant_id', $merchant->id)
            ->where(
                'pickup_location_id',
                $pickupLocation->id
            )
            ->whereIn('status', [
                'pending',
                'assigned',
                'started',
                'arrived',
            ])
            ->latest('id')
            ->first();

        /*
        |--------------------------------------------------------------------------
        | Create pickup if no active pickup exists
        |--------------------------------------------------------------------------
        */

        if (! $pickup) {
            $pickup = $this->createPickup(
                merchant: $merchant,
                pickupLocation: $pickupLocation,
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Attach shipment
        |--------------------------------------------------------------------------
        */

        $this->attachShipment(
            pickup: $pickup,
            shipment: $shipment,
        );
    }

    /**
     * Create a pickup request.
     */
    private function createPickup(
        Merchant $merchant,
        MerchantPickupLocation $pickupLocation,
    ): PickupRequest {
        /*
        |--------------------------------------------------------------------------
        | Resolve pickup name
        |--------------------------------------------------------------------------
        |
        | Your pickup_requests table requires pickup_name.
        |
        | MerchantPickupLocation normally contains the merchant's
        | configured pickup/store name.
        |
        */

        $pickupName =
            $pickupLocation->name
            ?? $pickupLocation->pickup_name
            ?? $pickupLocation->location_name
            ?? $merchant->business_name
            ?? $merchant->name
            ?? 'Merchant Pickup';

        /*
        |--------------------------------------------------------------------------
        | Build pickup data
        |--------------------------------------------------------------------------
        */

        $data = [
            'merchant_id' =>
                $merchant->id,

            'pickup_location_id' =>
                $pickupLocation->id,

            'pickup_name' =>
                $pickupName,

            'status' =>
                'pending',

            'requested_at' =>
                now(),
        ];

        /*
        |--------------------------------------------------------------------------
        | Optional pickup fields
        |--------------------------------------------------------------------------
        |
        | These are added only if the columns actually exist.
        |
        | This protects the service from minor schema differences.
        |
        */

        $columns = DB::getSchemaBuilder()
            ->getColumnListing('pickup_requests');

        $optionalData = [
            'pickup_phone' =>
                $pickupLocation->phone
                ?? $pickupLocation->contact_phone
                ?? null,

            'pickup_email' =>
                $pickupLocation->email
                ?? $pickupLocation->contact_email
                ?? null,

            'pickup_address' =>
                $pickupLocation->address
                ?? $pickupLocation->pickup_address
                ?? null,

            'pickup_city' =>
                $pickupLocation->city
                ?? null,

            'pickup_area' =>
                $pickupLocation->area
                ?? null,

            'pickup_lat' =>
                $pickupLocation->latitude
                ?? $pickupLocation->lat
                ?? null,

            'pickup_lng' =>
                $pickupLocation->longitude
                ?? $pickupLocation->lng
                ?? null,
        ];

        foreach ($optionalData as $key => $value) {
            if (
                in_array($key, $columns, true)
                && $value !== null
            ) {
                $data[$key] = $value;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Protect against schema differences
        |--------------------------------------------------------------------------
        */

        $data = array_intersect_key(
            $data,
            array_flip($columns)
        );

        /*
        |--------------------------------------------------------------------------
        | Create
        |--------------------------------------------------------------------------
        */

        return PickupRequest::query()->create($data);
    }

    /**
     * Attach shipment to pickup.
     */
    private function attachShipment(
        PickupRequest $pickup,
        Shipment $shipment,
    ): void {
        /*
        |--------------------------------------------------------------------------
        | IMPORTANT
        |--------------------------------------------------------------------------
        |
        | Correct table:
        |
        | pickup_request_shipments
        |
        | NOT:
        |
        | pickup_shipments
        |
        */

        $exists = DB::table('pickup_request_shipments')
            ->where(
                'pickup_request_id',
                $pickup->id
            )
            ->where(
                'shipment_id',
                $shipment->id
            )
            ->exists();

        if ($exists) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Insert pivot relationship
        |--------------------------------------------------------------------------
        */

        $columns = DB::getSchemaBuilder()
            ->getColumnListing('pickup_request_shipments');

        $data = [
            'pickup_request_id' =>
                $pickup->id,

            'shipment_id' =>
                $shipment->id,

            'added_at' =>
                now(),

            'status' =>
                'pending',

            'created_at' =>
                now(),

            'updated_at' =>
                now(),
        ];

        $data = array_intersect_key(
            $data,
            array_flip($columns)
        );

        DB::table('pickup_request_shipments')
            ->insert($data);
    }
}