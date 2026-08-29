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
     * Pickup grouping:
     *
     *     merchant
     *          +
     *     pickup location
     *
     * This means:
     *
     * Store A / Location 1 -> Pickup A
     * Store A / Location 2 -> Pickup B
     * Store B / Location 1 -> Pickup C
     *
     * A shipment created while an active pickup exists
     * is attached to that pickup.
     *
     * If no active pickup exists, a new pickup is created.
     */
    public function attachShipmentToActivePickup(
        Shipment $shipment,
        Merchant $merchant,
        MerchantPickupLocation $pickupLocation,
    ): PickupRequest {

        /*
        |--------------------------------------------------------------------------
        | Merchant ownership
        |--------------------------------------------------------------------------
        */

        if (
            isset($pickupLocation->merchant_id)
            &&
            (int) $pickupLocation->merchant_id !== (int) $merchant->id
        ) {
            throw ValidationException::withMessages([
                'pickup_location_id' => [
                    'Pickup location does not belong to this merchant.',
                ],
            ]);
        }

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
                | Create pickup if none exists
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

                /*
                |--------------------------------------------------------------------------
                | Return fresh pickup
                |--------------------------------------------------------------------------
                */

                return $pickup->fresh([
                    'merchant',
                    'branch',
                    'subBranch',
                    'pickupBranch',
                    'pickupSubBranch',
                    'pickupLocation',
                    'assignedStaff',
                    'shipments.shipment',
                ]);
            }
        );
    }

    /**
     * Pickup statuses that can still receive new shipments.
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
     * Create a pickup request from MerchantPickupLocation.
     *
     * IMPORTANT:
     *
     * pickup_requests has required pickup information.
     *
     * Therefore we copy the merchant pickup-location data
     * into the pickup request.
     */
    private function createPickup(
        Merchant $merchant,
        MerchantPickupLocation $pickupLocation,
    ): PickupRequest {

        /*
        |--------------------------------------------------------------------------
        | Build pickup request data
        |--------------------------------------------------------------------------
        */

        $data = [
            /*
            |----------------------------------------------------------------------
            | Ownership
            |----------------------------------------------------------------------
            */

            'merchant_id' =>
                $merchant->id,

            'pickup_location_id' =>
                $pickupLocation->id,

            /*
            |----------------------------------------------------------------------
            | Pickup information
            |----------------------------------------------------------------------
            */

            'pickup_name' =>
                $this->value(
                    $pickupLocation,
                    [
                        'name',
                        'pickup_name',
                        'location_name',
                        'store_name',
                    ]
                )
                ??
                $merchant->name
                ??
                'Merchant Pickup',

            'pickup_phone' =>
                $this->value(
                    $pickupLocation,
                    [
                        'phone',
                        'pickup_phone',
                        'contact_phone',
                        'mobile',
                    ]
                )
                ??
                $this->value(
                    $merchant,
                    [
                        'phone',
                        'mobile',
                        'contact_phone',
                    ]
                ),

            'pickup_email' =>
                $this->value(
                    $pickupLocation,
                    [
                        'email',
                        'pickup_email',
                        'contact_email',
                    ]
                )
                ??
                $this->value(
                    $merchant,
                    [
                        'email',
                        'contact_email',
                    ]
                ),

            'pickup_address' =>
                $this->value(
                    $pickupLocation,
                    [
                        'address',
                        'pickup_address',
                        'full_address',
                    ]
                ),

            'pickup_city' =>
                $this->value(
                    $pickupLocation,
                    [
                        'city',
                        'pickup_city',
                    ]
                ),

            'pickup_area' =>
                $this->value(
                    $pickupLocation,
                    [
                        'area',
                        'pickup_area',
                        'locality',
                    ]
                ),

            'pickup_lat' =>
                $this->value(
                    $pickupLocation,
                    [
                        'latitude',
                        'lat',
                        'pickup_lat',
                    ]
                ),

            'pickup_lng' =>
                $this->value(
                    $pickupLocation,
                    [
                        'longitude',
                        'lng',
                        'pickup_lng',
                    ]
                ),

            /*
            |----------------------------------------------------------------------
            | Branch information
            |----------------------------------------------------------------------
            */

            'branch_id' =>
                $this->value(
                    $pickupLocation,
                    [
                        'branch_id',
                    ]
                ),

            'sub_branch_id' =>
                $this->value(
                    $pickupLocation,
                    [
                        'sub_branch_id',
                    ]
                ),

            'pickup_branch_id' =>
                $this->value(
                    $pickupLocation,
                    [
                        'pickup_branch_id',
                    ]
                )
                ??
                $this->value(
                    $pickupLocation,
                    [
                        'branch_id',
                    ]
                ),

            'pickup_sub_branch_id' =>
                $this->value(
                    $pickupLocation,
                    [
                        'pickup_sub_branch_id',
                    ]
                )
                ??
                $this->value(
                    $pickupLocation,
                    [
                        'sub_branch_id',
                    ]
                ),

            /*
            |----------------------------------------------------------------------
            | Status
            |----------------------------------------------------------------------
            */

            'status' =>
                'pending',

            'requested_at' =>
                now(),
        ];

        /*
        |--------------------------------------------------------------------------
        | Remove null values only
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | Do NOT remove pickup_name.
        |
        */

        $data = array_filter(
            $data,
            static fn ($value) => $value !== null
        );

        /*
        |--------------------------------------------------------------------------
        | Protect against schema differences
        |--------------------------------------------------------------------------
        */

        $columns = DB::getSchemaBuilder()
            ->getColumnListing('pickup_requests');

        $data = array_intersect_key(
            $data,
            array_flip($columns)
        );

        /*
        |--------------------------------------------------------------------------
        | Required field protection
        |--------------------------------------------------------------------------
        */

        if (
            empty($data['pickup_name'])
        ) {
            throw ValidationException::withMessages([
                'pickup_location_id' => [
                    'Pickup location does not contain a valid pickup name.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Create pickup
        |--------------------------------------------------------------------------
        */

        return PickupRequest::query()->create(
            $data
        );
    }

    /**
     * Attach shipment to pickup_shipments.
     */
    private function attachShipment(
        PickupRequest $pickup,
        Shipment $shipment,
    ): void {

        /*
        |--------------------------------------------------------------------------
        | Prevent duplicate attachment
        |--------------------------------------------------------------------------
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

            'shipment_id' =>
                $shipment->id,

            'created_at' =>
                now(),

            'updated_at' =>
                now(),
        ]);
    }

    /**
     * Safely retrieve a value from a model.
     *
     * Different projects may use:
     *
     * name
     * pickup_name
     * location_name
     * etc.
     */
    private function value(
        object $model,
        array $attributes
    ): mixed {

        foreach ($attributes as $attribute) {

            $value = $model->getAttribute(
                $attribute
            );

            if (
                $value !== null
                &&
                $value !== ''
            ) {
                return $value;
            }
        }

        return null;
    }
}