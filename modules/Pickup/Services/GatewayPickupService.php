<?php

declare(strict_types=1);

namespace Modules\Pickup\Services;

use App\Support\CourierStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantPickupLocation;
use Modules\Pickup\Models\PickupRequest;
use Modules\Pickup\Models\PickupRequestShipment;
use Modules\Pickup\Support\PickupStatus;
use Modules\Shipment\Models\Shipment;

final class GatewayPickupService
{
    public function create(
        int $merchantId,
        array $data
    ): PickupRequest {
        $merchant =
            Merchant::query()
                ->find($merchantId);

        if (! $merchant) {
            throw ValidationException::withMessages([
                'merchant' =>
                    'Authenticated merchant was not found.',
            ]);
        }

        if ($merchant->status !== 'active') {
            throw ValidationException::withMessages([
                'merchant' =>
                    'Merchant account is not active.',
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

                $pickupLocation =
                    MerchantPickupLocation::query()
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
                        'pickup_location_id' =>
                            'Pickup location does not belong to this merchant.',
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Check whether an OPEN pickup already exists
                |--------------------------------------------------------------------------
                |
                | Store should normally create only one pickup request.
                |
                | If it retries, return the same open pickup.
                |
                */

                $existing =
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
                            PickupStatus::open()
                        )
                        ->lockForUpdate()
                        ->latest('id')
                        ->first();

                if ($existing) {
                    return $existing->load([
                        'merchant',
                        'pickupLocation',
                        'shipments.shipment',
                        'pickupBranch',
                        'pickupSubBranch',
                        'assignedStaff',
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Get shipment tracking numbers
                |--------------------------------------------------------------------------
                */

                $trackingNumbers =
                    collect(
                        $data[
                            'shipment_tracking_numbers'
                        ]
                    )
                    ->map(
                        static fn ($value): string =>
                            trim((string) $value)
                    )
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if ($trackingNumbers === []) {
                    throw ValidationException::withMessages([
                        'shipment_tracking_numbers' =>
                            'At least one shipment is required.',
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Load shipments
                |--------------------------------------------------------------------------
                */

                $shipments =
                    Shipment::query()
                        ->where(
                            'merchant_id',
                            $merchant->id
                        )
                        ->whereIn(
                            'tracking_number',
                            $trackingNumbers
                        )
                        ->get();

                $found =
                    $shipments
                        ->pluck('tracking_number')
                        ->map(
                            static fn ($value): string =>
                                (string) $value
                        )
                        ->all();

                $missing =
                    array_values(
                        array_diff(
                            $trackingNumbers,
                            $found
                        )
                    );

                if ($missing !== []) {
                    throw ValidationException::withMessages([
                        'shipment_tracking_numbers' =>
                            'Some shipments were not found.',

                        'missing_shipments' =>
                            $missing,
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Validate shipments
                |--------------------------------------------------------------------------
                */

                foreach ($shipments as $shipment) {

                    if (
                        ! in_array(
                            $shipment->status,
                            [
                                CourierStatus::AWAITING_PICKUP,
                                CourierStatus::BOOKED,
                                CourierStatus::PICKUP_ASSIGNED,
                            ],
                            true
                        )
                    ) {
                        throw ValidationException::withMessages([
                            'shipment_tracking_numbers' =>
                                "Shipment {$shipment->tracking_number} "
                                . 'is not eligible for pickup.',
                        ]);
                    }

                    if (
                        (int) $shipment->pickup_location_id
                        !== (int) $pickupLocation->id
                    ) {
                        throw ValidationException::withMessages([
                            'shipment_tracking_numbers' =>
                                "Shipment {$shipment->tracking_number} "
                                . 'belongs to a different pickup location.',
                        ]);
                    }

                    $alreadyInPickup =
                        PickupRequestShipment::query()
                            ->where(
                                'shipment_id',
                                $shipment->id
                            )
                            ->whereNull('removed_at')
                            ->whereHas(
                                'pickupRequest',
                                function ($query) {
                                    $query->whereIn(
                                        'status',
                                        PickupStatus::active()
                                    );
                                }
                            )
                            ->exists();

                    if ($alreadyInPickup) {
                        throw ValidationException::withMessages([
                            'shipment_tracking_numbers' =>
                                "Shipment {$shipment->tracking_number} "
                                . 'is already assigned to an active pickup request.',
                        ]);
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Origin branch
                |--------------------------------------------------------------------------
                */

                $originBranchIds =
                    $shipments
                        ->pluck('origin_branch_id')
                        ->filter()
                        ->unique()
                        ->values();

                $originSubBranchIds =
                    $shipments
                        ->pluck('origin_sub_branch_id')
                        ->filter()
                        ->unique()
                        ->values();

                if ($originBranchIds->count() !== 1) {
                    throw ValidationException::withMessages([
                        'shipment_tracking_numbers' =>
                            'All shipments must belong to the same origin branch.',
                    ]);
                }

                $branchId =
                    (int) $originBranchIds->first();

                $subBranchId =
                    $originSubBranchIds->count() === 1
                        ? (int) $originSubBranchIds->first()
                        : null;

                /*
                |--------------------------------------------------------------------------
                | CREATE PICKUP
                |--------------------------------------------------------------------------
                |
                | NO pickup cutoff here.
                |
                */

                $pickup =
                    PickupRequest::query()
                        ->create([
                            'request_number' =>
                                $data['pickup_request_number'],

                            'merchant_id' =>
                                $merchant->id,

                            'branch_id' =>
                                $branchId,

                            'sub_branch_id' =>
                                $subBranchId,

                            'pickup_branch_id' =>
                                $branchId,

                            'pickup_sub_branch_id' =>
                                $subBranchId,

                            'pickup_location_id' =>
                                $pickupLocation->id,

                            'pickup_name' =>
                                $pickupLocation->name
                                ?? $merchant->name
                                ?? null,

                            'pickup_phone' =>
                                $pickupLocation->phone
                                ?? null,

                            'pickup_address' =>
                                $pickupLocation->address
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

                            'preferred_pickup_at' =>
                                $data['preferred_pickup_at']
                                ?? null,

                            'parcel_quantity' =>
                                $shipments->count(),

                            'status' =>
                                PickupStatus::REQUESTED,

                            'remarks' =>
                                $data['remarks']
                                ?? null,

                            'requested_at' =>
                                now(),
                        ]);

                /*
                |--------------------------------------------------------------------------
                | Attach initial shipments
                |--------------------------------------------------------------------------
                */

                foreach ($shipments as $shipment) {

                    PickupRequestShipment::query()
                        ->create([
                            'pickup_request_id' =>
                                $pickup->id,

                            'shipment_id' =>
                                $shipment->id,

                            'remarks' =>
                                null,
                        ]);

                    if (
                        $shipment->status
                        === CourierStatus::AWAITING_PICKUP
                    ) {
                        $shipment->status =
                            CourierStatus::PICKUP_ASSIGNED;

                        $shipment->merchant_status =
                            CourierStatus::merchantStatus(
                                CourierStatus::PICKUP_ASSIGNED
                            );

                        $shipment->save();
                    }
                }

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
}