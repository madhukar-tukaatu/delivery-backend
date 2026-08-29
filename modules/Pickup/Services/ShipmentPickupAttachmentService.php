<?php

declare(strict_types=1);

namespace Modules\Pickup\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantPickupLocation;
use Modules\Pickup\Models\PickupRequest;
use Modules\Pickup\Models\PickupRequestShipment;
use Modules\Pickup\Support\PickupStatus;
use Modules\Shipment\Models\Shipment;

final class ShipmentPickupAttachmentService
{
    /**
     * Attach a shipment to the merchant's currently open pickup.
     *
     * Rules:
     *
     * 1. One open pickup exists per merchant + pickup location.
     * 2. If it exists, attach shipment to it.
     * 3. If none exists, create a new pickup container.
     * 4. Never create a second open pickup for the same location.
     * 5. Once pickup is completed/failed/cancelled, the next shipment
     *    creates a new pickup request.
     */
    public function attachShipmentToActivePickup(
        Shipment $shipment,
        Merchant $merchant,
        MerchantPickupLocation $pickupLocation,
    ): PickupRequest {
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

        return DB::transaction(
            function () use (
                $shipment,
                $merchant,
                $pickupLocation
            ): PickupRequest {

                /*
                |--------------------------------------------------------------------------
                | Find existing open pickup
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
                        PickupStatus::acceptingShipments()
                    )
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                /*
                |--------------------------------------------------------------------------
                | Create new pickup if no open pickup exists
                |--------------------------------------------------------------------------
                */

                if (! $pickup) {
                    $pickup = $this->createPickup(
                        merchant: $merchant,
                        pickupLocation: $pickupLocation,
                        shipment: $shipment,
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
                | Update quantity
                |--------------------------------------------------------------------------
                */

                $pickup->parcel_quantity = $pickup
                    ->activeShipments()
                    ->count();

                $pickup->save();

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

    /*
    |--------------------------------------------------------------------------
    | Create Pickup
    |--------------------------------------------------------------------------
    */

    private function createPickup(
        Merchant $merchant,
        MerchantPickupLocation $pickupLocation,
        Shipment $shipment,
    ): PickupRequest {

        $pickupName =
            $pickupLocation->name
            ?? $pickupLocation->pickup_name
            ?? $pickupLocation->location_name
            ?? $merchant->business_name
            ?? $merchant->name
            ?? 'Merchant Pickup';

        $branchId =
            $shipment->origin_branch_id;

        $subBranchId =
            $shipment->origin_sub_branch_id;

        if (! $branchId) {
            throw ValidationException::withMessages([
                'shipment' => [
                    'Shipment does not have an origin branch.',
                ],
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Generate Tukaatu pickup request number
        |--------------------------------------------------------------------------
        */

        $requestNumber = $this->generateRequestNumber();

        return PickupRequest::query()->create([
            'request_number' =>
                $requestNumber,

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
                $pickupName,

            'pickup_phone' =>
                $pickupLocation->phone
                ?? $pickupLocation->contact_phone
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

            'parcel_quantity' =>
                0,

            /*
            |--------------------------------------------------------------------------
            | Shipment exists but merchant has not requested rider yet.
            |--------------------------------------------------------------------------
            */

            'status' =>
                PickupStatus::REQUESTED,

            'requested_at' =>
                null,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Attach shipment
    |--------------------------------------------------------------------------
    */

    private function attachShipment(
        PickupRequest $pickup,
        Shipment $shipment,
    ): void {

        $exists = PickupRequestShipment::query()
            ->where(
                'pickup_request_id',
                $pickup->id
            )
            ->where(
                'shipment_id',
                $shipment->id
            )
            ->whereNull('removed_at')
            ->exists();

        if ($exists) {
            return;
        }

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
    | Generate pickup number
    |--------------------------------------------------------------------------
    */

    private function generateRequestNumber(): string
    {
        do {
            $number =
                'PICKUP-'
                . now()->format('Ymd')
                . '-'
                . strtoupper(
                    substr(
                        bin2hex(random_bytes(4)),
                        0,
                        8
                    )
                );

        } while (
            PickupRequest::query()
                ->where(
                    'request_number',
                    $number
                )
                ->exists()
        );

        return $number;
    }
}