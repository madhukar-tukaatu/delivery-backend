<?php

declare(strict_types=1);

namespace Modules\Pickup\Services;

use Illuminate\Support\Facades\DB;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantPickupLocation;
use Modules\Pickup\Models\PickupRequest;
use Modules\Shipment\Models\Shipment;

final class ShipmentPickupAttachmentService
{
    /**
     * Attach a shipment to the merchant's currently active pickup.
     *
     * Business flow:
     *
     * 1. Shipment can be created at any time.
     * 2. No shipment cutoff is enforced here.
     * 3. If an active pickup already exists for this store/location:
     *      - do NOT create another pickup
     *      - preserve assigned rider
     *      - add shipment to existing pickup
     *      - notify rider
     *
     * 4. If no active pickup exists:
     *      - create a new pickup request
     *      - attach shipment
     *
     * 5. Once pickup is completed, the pickup becomes closed.
     * 6. A shipment created after completion belongs to a NEW pickup.
     */
    public function attachShipmentToActivePickup(
        Shipment $shipment,
        Merchant $merchant,
        MerchantPickupLocation $pickupLocation,
    ): PickupRequest {

        return DB::transaction(
            function () use (
                $shipment,
                $merchant,
                $pickupLocation
            ): PickupRequest {

                /*
                |--------------------------------------------------------------------------
                | Find current active pickup
                |--------------------------------------------------------------------------
                |
                | IMPORTANT:
                |
                | We intentionally allow:
                |
                | pending
                | assigned
                | accepted
                | started
                | on_the_way
                | arrived
                |
                | Therefore a store can continue creating shipments while
                | the rider is travelling to the store.
                |
                */

                $pickup = PickupRequest::query()
                    ->where(
                        'merchant_id',
                        $merchant->id
                    )
                    ->where(
                        'pickup_location_id',
                        $pickupLocation->id
                    )
                    ->whereIn(
                        'status',
                        $this->attachableStatuses()
                    )
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                /*
                |--------------------------------------------------------------------------
                | No active pickup
                |--------------------------------------------------------------------------
                */

                if (! $pickup) {

                    $pickup = $this->createPickup(
                        merchant: $merchant,
                        pickupLocation: $pickupLocation,
                        shipment: $shipment,
                    );

                    return $pickup->fresh([
                        'assignedStaff',
                        'shipments.shipment',
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Existing pickup
                |--------------------------------------------------------------------------
                |
                | DO NOT:
                |
                | - create another pickup
                | - reset rider
                | - change assigned_to
                | - change assigned_staff_id
                | - reset started_at
                | - reset arrived_at
                | - change pickup status
                |
                | Simply add the new shipment.
                |
                */

                $this->attachShipment(
                    pickup: $pickup,
                    shipment: $shipment,
                );

                /*
                |--------------------------------------------------------------------------
                | Notify rider
                |--------------------------------------------------------------------------
                */

                if ($pickup->assigned_staff_id) {

                    $this->notifyRider(
                        pickup: $pickup,
                        shipment: $shipment,
                    );
                }

                return $pickup->fresh([
                    'assignedStaff',
                    'shipments.shipment',
                ]);
            }
        );
    }

    /**
     * Pickup statuses that are still open for receiving shipments.
     *
     * A shipment may be added while:
     *
     * pending
     * assigned
     * accepted
     * started
     * on_the_way
     * arrived
     *
     * The important boundary is COMPLETE.
     */
    private function attachableStatuses(): array
    {
        return [
            'pending',
            'assigned',
            'accepted',
            'started',
            'on_the_way',
            'arrived',
        ];
    }

    /**
     * Attach shipment to pickup.
     *
     * Uses the pickup_shipments pivot table.
     */
    private function attachShipment(
        PickupRequest $pickup,
        Shipment $shipment
    ): void {

        $exists = DB::table('pickup_shipments')
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

        DB::table('pickup_shipments')->insert([
            'pickup_request_id' => $pickup->id,
            'shipment_id'       => $shipment->id,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    /**
     * Create a new pickup request.
     */
    private function createPickup(
        Merchant $merchant,
        MerchantPickupLocation $pickupLocation,
        Shipment $shipment
    ): PickupRequest {

        /*
        |--------------------------------------------------------------------------
        | Create pickup
        |--------------------------------------------------------------------------
        */

        $pickupData = [
            'merchant_id'        => $merchant->id,
            'pickup_location_id' => $pickupLocation->id,
            'status'             => 'pending',
            'requested_at'       => now(),
        ];

        /*
        |--------------------------------------------------------------------------
        | Only use columns that actually exist
        |--------------------------------------------------------------------------
        |
        | This keeps the service compatible with your current schema.
        |
        */

        $columns = DB::getSchemaBuilder()
            ->getColumnListing('pickup_requests');

        $pickupData = array_intersect_key(
            $pickupData,
            array_flip($columns)
        );

        $pickup = PickupRequest::query()->create(
            $pickupData
        );

        /*
        |--------------------------------------------------------------------------
        | Attach first shipment
        |--------------------------------------------------------------------------
        */

        $this->attachShipment(
            pickup: $pickup,
            shipment: $shipment,
        );

        return $pickup;
    }

    /**
     * Notify assigned rider that another shipment
     * has been added to the active pickup.
     */
    private function notifyRider(
        PickupRequest $pickup,
        Shipment $shipment
    ): void {

        /*
        |--------------------------------------------------------------------------
        | IMPORTANT
        |--------------------------------------------------------------------------
        |
        | Connect this to your existing notification system.
        |
        | Do not create another pickup.
        |
        | Example notification payload:
        |
        | pickup_request_id
        | shipment_id
        | tracking_number
        | type = shipment_added_to_pickup
        |
        */

        /*
         * Example:
         *
         * RiderNotification::create([
         *     'user_id'          => $pickup->assigned_staff_id,
         *     'type'             => 'shipment_added_to_pickup',
         *     'pickup_request_id'=> $pickup->id,
         *     'shipment_id'      => $shipment->id,
         *     'message'          => 'A new shipment has been added to your pickup.',
         * ]);
         */

        /*
         * Or dispatch your existing notification event/job here.
         */
    }
}