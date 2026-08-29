<?php

declare (strict_types = 1);

namespace Modules\Pickup\Services;

use Illuminate\Support\Facades\DB;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\PickupLocation;
use Modules\Pickup\Models\PickupRequest;
use Modules\Shipment\Models\Shipment;

final class ShipmentPickupAttachmentService
{
    /**
     * Attach a shipment to the merchant's currently open pickup.
     *
     * Rules:
     *
     * 1. Never create a second pickup when an active pickup exists.
     * 2. Existing rider assignment is preserved.
     * 3. New shipment is added to that pickup.
     * 4. Rider is notified about the new shipment.
     * 5. If no active pickup exists, create a new pickup.
     */
    public function attachShipmentToActivePickup(
        Shipment $shipment,
        Merchant $merchant,
        PickupLocation $pickupLocation,
    ): PickupRequest {

        return DB::transaction(
            function () use (
                $shipment,
                $merchant,
                $pickupLocation
            ): PickupRequest {

                /*
                |--------------------------------------------------------------------------
                | Find existing active pickup
                |--------------------------------------------------------------------------
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
                    $pickup =
                    $this->createPickup(
                        $merchant,
                        $pickupLocation,
                        $shipment
                    );

                    return $pickup;
                }

                /*
                |--------------------------------------------------------------------------
                | Existing pickup found
                |--------------------------------------------------------------------------
                |
                | IMPORTANT:
                |
                | We DO NOT reset:
                |
                | - assigned rider
                | - assignment
                | - started status
                | - arrived status
                |
                | The shipment simply joins the existing pickup.
                |
                */

                $this->attachShipment(
                    $pickup,
                    $shipment
                );

                /*
                |--------------------------------------------------------------------------
                | Notify existing rider
                |--------------------------------------------------------------------------
                */

                if ($pickup->assigned_staff_id) {
                    $this->notifyRider(
                        $pickup,
                        $shipment
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
     * Pickup states that can still receive shipments.
     *
     * Adjust these values to your actual PickupStatus constants.
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
     */
    private function attachShipment(
        PickupRequest $pickup,
        Shipment $shipment
    ): void {
        /*
         * Replace this with your actual pivot/attachment
         * implementation if your schema uses another table.
         */

        $exists = DB::table(
            'pickup_shipments'
        )
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

        DB::table(
            'pickup_shipments'
        )->insert([
            'pickup_request_id' =>
            $pickup->id,

            'shipment_id'       =>
            $shipment->id,

            'created_at'        =>
            now(),

            'updated_at'        =>
            now(),
        ]);
    }

    /**
     * Create pickup when none exists.
     */
    private function createPickup(
        Merchant $merchant,
        $pickupLocation,
        Shipment $shipment
    ): PickupRequest {

        /*
         * IMPORTANT:
         *
         * Adapt these fields to your existing
         * PickupRequest model/database schema.
         */

        $pickup = PickupRequest::query()->create([
            'merchant_id'        =>
            $merchant->id,

            'pickup_location_id' =>
            $pickupLocation->id,

            'status'             =>
            'pending',

            'requested_at'       =>
            now(),
        ]);

        $this->attachShipment(
            $pickup,
            $shipment
        );

        return $pickup->fresh();
    }

    /**
     * Notify assigned rider.
     *
     * Connect this to your existing notification system.
     */
    private function notifyRider(
        PickupRequest $pickup,
        Shipment $shipment
    ): void {
        /*
         * Example:
         *
         * RiderNotification::create([
         *     'user_id' => $pickup->assigned_staff_id,
         *     'type' => 'pickup_shipment_added',
         *     'pickup_request_id' => $pickup->id,
         *     'shipment_id' => $shipment->id,
         * ]);
         *
         * Or dispatch your existing notification job/event.
         */
    }
}
