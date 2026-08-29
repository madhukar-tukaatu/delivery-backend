<?php

declare(strict_types=1);

namespace Modules\Pickup\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantPickupLocation;
use Modules\Pickup\Models\PickupRequest;
use Modules\Pickup\Support\PickupStatus;
use Modules\Shipment\Models\Shipment;

final class GatewayPickupService
{
    /**
     * Create a pickup request from an external Store Manager.
     *
     * IMPORTANT:
     *
     * Shipment creation does NOT create a pickup request.
     *
     * The merchant explicitly requests pickup through:
     *
     * POST /api/v1/gateway/pickups
     *
     * At that point we:
     *
     * 1. Validate merchant
     * 2. Validate pickup location
     * 3. Find an existing active pickup, if any
     * 4. Otherwise create a new pickup request
     * 5. Find all eligible awaiting_pickup shipments
     * 6. Attach those shipments to the pickup
     * 7. Return the pickup
     */
    public function create(
        int $merchantId,
        array $data
    ): PickupRequest {

        /*
        |--------------------------------------------------------------------------
        | Merchant
        |--------------------------------------------------------------------------
        */

        $merchant = Merchant::query()
            ->find($merchantId);

        if (! $merchant) {
            throw ValidationException::withMessages([
                'merchant' => [
                    'Authenticated merchant was not found.',
                ],
            ]);
        }

        if ($merchant->status !== 'active') {
            throw ValidationException::withMessages([
                'merchant' => [
                    'Merchant account is not active.',
                ],
            ]);
        }

        return DB::transaction(
            function () use (
                $merchant,
                $data
            ): PickupRequest {

                /*
                |--------------------------------------------------------------------------
                | Pickup location
                |--------------------------------------------------------------------------
                */

                $pickupLocation = MerchantPickupLocation::query()
                    ->where(
                        'id',
                        $data['pickup_location_id']
                    )
                    ->where(
                        'merchant_id',
                        $merchant->id
                    )
                    ->first();

                if (! $pickupLocation) {
                    throw ValidationException::withMessages([
                        'pickup_location_id' => [
                            'Pickup location does not belong to this merchant.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Find existing active pickup
                |--------------------------------------------------------------------------
                |
                | If there is already a pickup which has not been completed,
                | failed, or cancelled, do not create another pickup batch.
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
                        PickupStatus::active()
                    )
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                /*
                |--------------------------------------------------------------------------
                | Create pickup request if none exists
                |--------------------------------------------------------------------------
                */

                if (! $pickup) {

                    $pickup = PickupRequest::query()->create([
                        'merchant_id' =>
                            $merchant->id,

                        'pickup_location_id' =>
                            $pickupLocation->id,

                        'status' =>
                            PickupStatus::REQUESTED,

                        'requested_at' =>
                            now(),

                        'preferred_pickup_at' =>
                            $data['preferred_pickup_at']
                            ?? null,

                        'remarks' =>
                            $data['remarks']
                            ?? null,

                        'parcel_quantity' =>
                            0,
                    ]);
                } else {

                    /*
                    |--------------------------------------------------------------------------
                    | Existing active pickup
                    |--------------------------------------------------------------------------
                    */

                    if (
                        $pickup->status ===
                        PickupStatus::REQUESTED
                    ) {
                        $pickup->requested_at =
                            $pickup->requested_at
                            ?? now();
                    }

                    if (
                        ! empty(
                            $data['preferred_pickup_at']
                        )
                    ) {
                        $pickup->preferred_pickup_at =
                            $data['preferred_pickup_at'];
                    }

                    if (
                        ! empty(
                            $data['remarks']
                        )
                    ) {
                        $pickup->remarks =
                            $data['remarks'];
                    }

                    $pickup->save();
                }

                /*
                |--------------------------------------------------------------------------
                | Attach eligible shipments
                |--------------------------------------------------------------------------
                |
                | Only shipments which:
                |
                | - belong to this merchant
                | - belong to this pickup location
                | - are awaiting pickup
                | - are not already attached to another active pickup
                |
                */

                $this->attachEligibleShipments(
                    pickup: $pickup,
                    merchantId: $merchant->id,
                    pickupLocationId: $pickupLocation->id
                );

                /*
                |--------------------------------------------------------------------------
                | Recalculate quantity
                |--------------------------------------------------------------------------
                */

                $pickup->parcel_quantity =
                    $pickup
                        ->activeShipments()
                        ->count();

                $pickup->save();

                /*
                |--------------------------------------------------------------------------
                | Safety check
                |--------------------------------------------------------------------------
                */

                if (
                    $pickup->parcel_quantity <= 0
                ) {

                    /*
                    |--------------------------------------------------------------
                    | If we just created an empty pickup, remove it.
                    |--------------------------------------------------------------
                    |
                    | This prevents meaningless pickup requests when the
                    | merchant has no awaiting shipments.
                    |
                    */

                    if (
                        $pickup->wasRecentlyCreated
                    ) {
                        $pickup->delete();
                    }

                    throw ValidationException::withMessages([
                        'pickup' => [
                            'There are no shipments awaiting pickup for this pickup location.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Return complete pickup
                |--------------------------------------------------------------------------
                */

                return $pickup->fresh([
                    'merchant',
                    'pickupLocation',
                    'shipments.shipment',
                    'pickupBranch',
                    'pickupSubBranch',
                    'assignedStaff',
                ]);
            }
        );
    }

    /**
     * Attach shipments which are ready for pickup.
     *
     * A shipment can belong to only one pickup batch.
     */
    private function attachEligibleShipments(
        PickupRequest $pickup,
        int $merchantId,
        int $pickupLocationId
    ): void {

        /*
        |--------------------------------------------------------------------------
        | Find shipments
        |--------------------------------------------------------------------------
        */

        $shipments = Shipment::query()
            ->where(
                'merchant_id',
                $merchantId
            )
            ->where(
                'pickup_location_id',
                $pickupLocationId
            )
            ->where(
                'status',
                'awaiting_pickup'
            )
            ->whereDoesntHave(
                'pickupRequests',
                function ($query) {
                    $query->whereIn(
                        'status',
                        PickupStatus::active()
                    );
                }
            )
            ->lockForUpdate()
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Attach
        |--------------------------------------------------------------------------
        */

        foreach ($shipments as $shipment) {

            /*
            |----------------------------------------------------------------------
            | Adjust this relation name if your Shipment model uses another
            | relationship name.
            |----------------------------------------------------------------------
            */

            $pickup->shipments()->firstOrCreate([
                'shipment_id' =>
                    $shipment->id,
            ]);
        }
    }

    /**
     * Retrieve pickup belonging to merchant.
     */
    public function findForMerchant(
        int $merchantId,
        string $requestNumber
    ): PickupRequest {

        return PickupRequest::query()
            ->where(
                'merchant_id',
                $merchantId
            )
            ->where(
                'request_number',
                $requestNumber
            )
            ->with([
                'pickupLocation',
                'pickupBranch',
                'pickupSubBranch',
                'assignedStaff',
                'shipments.shipment',
            ])
            ->firstOrFail();
    }
}