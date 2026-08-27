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
        $merchant = Merchant::query()
            ->find($merchantId);

        if (! $merchant) {
            throw ValidationException::withMessages([
                'merchant' => 'Authenticated merchant was not found.',
            ]);
        }

        if ($merchant->status !== 'active') {
            throw ValidationException::withMessages([
                'merchant' => 'Merchant account is not active.',
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
                        ->where('id', $data['pickup_location_id'])
                        ->where('merchant_id', $merchant->id)
                        ->first();

                if (! $pickupLocation) {
                    throw ValidationException::withMessages([
                        'pickup_location_id' =>
                            'Pickup location does not belong to this merchant.',
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Idempotency
                |--------------------------------------------------------------------------
                |
                | The store may retry the same request.
                |
                */

                $existing = PickupRequest::query()
                    ->where('merchant_id', $merchant->id)
                    ->where(
                        'request_number',
                        $data['pickup_request_number']
                    )
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
                | Get shipments
                |--------------------------------------------------------------------------
                */

                $trackingNumbers =
                    collect(
                        $data['shipment_tracking_numbers']
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
                | Load merchant shipments
                |--------------------------------------------------------------------------
                */

                $shipments = Shipment::query()
                    ->where('merchant_id', $merchant->id)
                    ->whereIn(
                        'tracking_number',
                        $trackingNumbers
                    )
                    ->get();

                /*
                |--------------------------------------------------------------------------
                | Make sure every requested tracking number exists
                |--------------------------------------------------------------------------
                */

                $foundTrackingNumbers = $shipments
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
                            $foundTrackingNumbers
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
                | Validate every shipment
                |--------------------------------------------------------------------------
                */

                foreach ($shipments as $shipment) {

                    /*
                    |--------------------------------------------------------------------------
                    | Shipment must still be waiting for pickup
                    |--------------------------------------------------------------------------
                    */

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

                    /*
                    |--------------------------------------------------------------------------
                    | Shipment pickup location must match
                    |--------------------------------------------------------------------------
                    */

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

                    /*
                    |--------------------------------------------------------------------------
                    | Shipment must not already belong to an active pickup
                    |--------------------------------------------------------------------------
                    */

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
                | Resolve origin branch
                |--------------------------------------------------------------------------
                |
                | Shipment already has its origin branch determined during
                | shipment creation.
                |
                */

                $originBranchId =
                    $shipments
                        ->pluck('origin_branch_id')
                        ->filter()
                        ->unique()
                        ->values();

                $originSubBranchId =
                    $shipments
                        ->pluck('origin_sub_branch_id')
                        ->filter()
                        ->unique()
                        ->values();

                if ($originBranchId->count() !== 1) {
                    throw ValidationException::withMessages([
                        'shipment_tracking_numbers' =>
                            'All shipments in one pickup request must belong '
                            . 'to the same origin branch.',
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Sub branch
                |--------------------------------------------------------------------------
                */

                $branchId =
                    (int) $originBranchId->first();

                $subBranchId =
                    $originSubBranchId->count() === 1
                        ? (int) $originSubBranchId->first()
                        : null;

                /*
                |--------------------------------------------------------------------------
                | Pickup cutoff
                |--------------------------------------------------------------------------
                |
                | For Express / Same-Day:
                |
                | pickup request must be submitted before 12:00.
                |
                | NOTE:
                |
                | The 11:00 shipment assignment cutoff is already enforced
                | when the shipment is created.
                |
                */

                $containsPriorityShipment =
                    $shipments->contains(
                        static function (Shipment $shipment): bool {
                            return in_array(
                                strtolower(
                                    (string) $shipment->service_type
                                ),
                                [
                                    'express',
                                    'same_day',
                                    'same-day',
                                ],
                                true
                            );
                        }
                    );

                $now = now();

                if (
                    $containsPriorityShipment
                    &&
                    $now->format('H:i') >= '12:00'
                ) {
                    throw ValidationException::withMessages([
                        'shipment_tracking_numbers' =>
                            'Express/Same-Day pickup requests must be '
                            . 'submitted before 12:00.',
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Create pickup request
                |--------------------------------------------------------------------------
                */

                $pickup = PickupRequest::query()->create([
                    'request_number' =>
                        $data['pickup_request_number'],

                    'merchant_id' =>
                        $merchant->id,

                    /*
                    | Keep branch_id as the operational branch.
                    */

                    'branch_id' =>
                        $branchId,

                    'sub_branch_id' =>
                        $subBranchId,

                    /*
                    | Pickup branch fields.
                    */

                    'pickup_branch_id' =>
                        $branchId,

                    'pickup_sub_branch_id' =>
                        $subBranchId,

                    /*
                    | Pickup location.
                    */

                    'pickup_location_id' =>
                        $pickupLocation->id,

                    /*
                    | Snapshot pickup information.
                    */

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

                    /*
                    | Requested pickup time.
                    */

                    'preferred_pickup_at' =>
                        $data['preferred_pickup_at'] ?? null,

                    /*
                    | Number of shipments.
                    */

                    'parcel_quantity' =>
                        $shipments->count(),

                    /*
                    | Initial status.
                    */

                    'status' =>
                        PickupStatus::REQUESTED,

                    /*
                    | Remarks.
                    */

                    'remarks' =>
                        $data['remarks'] ?? null,

                    /*
                    | Request timestamp.
                    */

                    'requested_at' =>
                        now(),
                ]);

                /*
                |--------------------------------------------------------------------------
                | Attach shipments
                |--------------------------------------------------------------------------
                */

                foreach ($shipments as $shipment) {

                    PickupRequestShipment::query()->create([
                        'pickup_request_id' =>
                            $pickup->id,

                        'shipment_id' =>
                            $shipment->id,

                        'remarks' =>
                            null,
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Update shipment status
                |--------------------------------------------------------------------------
                */

                foreach ($shipments as $shipment) {

                    if (
                        $shipment->status
                        === CourierStatus::AWAITING_PICKUP
                    ) {
                        $shipment->status =
                            CourierStatus::PICKUP_ASSIGNED;

                        if (
                            method_exists(
                                $shipment,
                                'save'
                            )
                        ) {
                            $shipment->save();
                        }
                    }
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
                ]);
            }
        );
    }
}