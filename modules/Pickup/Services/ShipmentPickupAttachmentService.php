<?php

declare(strict_types=1);

namespace Modules\Pickup\Services;

use Illuminate\Support\Facades\DB;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\PickupLocation;
use Modules\Pickup\Models\PickupRequest;
use Modules\Shipment\Models\Shipment;

final class ShipmentPickupAttachmentService
{
    /**
     * Attach a newly-created shipment to the merchant's
     * currently open pickup.
     *
     * Business rule:
     *
     * A merchant has one active pickup container per
     * pickup location.
     *
     * The container remains open while:
     *
     * - pending
     * - assigned
     * - accepted
     * - started
     * - on_the_way
     * - arrived
     *
     * Therefore:
     *
     * Store creates shipment
     *       ↓
     * Find active pickup
     *       ↓
     * Existing pickup?
     *       ↓
     * YES → attach shipment
     *       ↓
     * Rider assigned?
     *       ↓
     * YES → notify rider
     *
     * If no pickup exists:
     *
     * Create pickup
     *       ↓
     * Attach shipment
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
                | Find currently open pickup
                |--------------------------------------------------------------------------
                */

                $pickup =
                    PickupRequest::query()
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
                | No open pickup
                |--------------------------------------------------------------------------
                */

                if (! $pickup) {

                    $pickup =
                        $this->createPickup(
                            merchant: $merchant,
                            pickupLocation: $pickupLocation,
                            shipment: $shipment
                        );

                    return $pickup->fresh([
                        'assignedStaff',
                        'shipments.shipment',
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Existing open pickup
                |--------------------------------------------------------------------------
                |
                | IMPORTANT:
                |
                | DO NOT:
                |
                | - create another pickup
                | - reset rider
                | - change assignment
                | - change rider
                | - restart pickup
                | - change pickup status
                |
                */

                $this->attachShipment(
                    pickup: $pickup,
                    shipment: $shipment
                );

                /*
                |--------------------------------------------------------------------------
                | Notify rider
                |--------------------------------------------------------------------------
                */

                if (
                    $pickup->assigned_staff_id
                ) {
                    $this->notifyRider(
                        pickup: $pickup,
                        shipment: $shipment
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
     * Pickup statuses that are still open.
     *
     * IMPORTANT:
     *
     * "arrived" remains attachable.
     *
     * The rider has reached the store but has NOT
     * completed the pickup yet.
     *
     * Therefore a store can still create a shipment
     * while the rider is at the store.
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
        |--------------------------------------------------------------------------
        | Prevent duplicate attachment
        |--------------------------------------------------------------------------
        */

        $exists =
            DB::table('pickup_shipments')
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

        DB::table('pickup_shipments')
            ->insert([
                'pickup_request_id' =>
                    $pickup->id,

                'shipment_id' =>
                    $shipment->id,

                'created_at' =>
                    now(),

                'updated_at' =>
                    now(),
            ]);
    }

    /**
     * Create the first pickup for a shipment.
     */
    private function createPickup(
        Merchant $merchant,
        PickupLocation $pickupLocation,
        Shipment $shipment
    ): PickupRequest {

        $pickup =
            PickupRequest::query()->create([
                'merchant_id' =>
                    $merchant->id,

                'pickup_location_id' =>
                    $pickupLocation->id,

                'status' =>
                    'pending',

                'requested_at' =>
                    now(),
            ]);

        $this->attachShipment(
            pickup: $pickup,
            shipment: $shipment
        );

        return $pickup;
    }

    /**
     * Notify rider that a new shipment has been
     * added to the pickup.
     *
     * Replace the implementation with your existing
     * notification/event system.
     */
    private function notifyRider(
        PickupRequest $pickup,
        Shipment $shipment
    ): void {

        /*
        |--------------------------------------------------------------------------
        | Option 1: Database notification
        |--------------------------------------------------------------------------
        |
        | If your User model uses Laravel notifications:
        |
        | $rider = User::find($pickup->assigned_staff_id);
        |
        | $rider?->notify(
        |     new NewShipmentAddedToPickupNotification(
        |         $pickup,
        |         $shipment
        |     )
        | );
        |
        |--------------------------------------------------------------------------
        */

        /*
        |--------------------------------------------------------------------------
        | Option 2: Domain event
        |--------------------------------------------------------------------------
        |
        | event(
        |     new ShipmentAddedToAssignedPickup(
        |         pickup: $pickup,
        |         shipment: $shipment
        |     )
        | );
        |
        |--------------------------------------------------------------------------
        */

        /*
        |--------------------------------------------------------------------------
        | Option 3: Existing notification service
        |--------------------------------------------------------------------------
        |
        | Connect your existing notification implementation here.
        |--------------------------------------------------------------------------
        */
    }
}