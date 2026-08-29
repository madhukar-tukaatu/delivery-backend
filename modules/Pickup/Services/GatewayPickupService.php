<?php

declare(strict_types=1);

namespace Modules\Pickup\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantPickupLocation;
use Modules\Pickup\Models\PickupRequest;
use Modules\Pickup\Support\PickupStatus;

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
                        'pickup_location_id' => [
                            'Pickup location does not belong to this merchant.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Find current open pickup
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
                        PickupStatus::active()
                    )
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                if (! $pickup) {
                    throw ValidationException::withMessages([
                        'pickup' => [
                            'There is no open pickup request for this pickup location. '
                            . 'Create at least one shipment first.',
                        ],
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Mark pickup as requested
                |--------------------------------------------------------------------------
                */

                if (
                    $pickup->status === PickupStatus::REQUESTED
                ) {
                    $pickup->requested_at =
                        $pickup->requested_at
                        ?? now();
                }

                /*
                |--------------------------------------------------------------------------
                | Update remarks
                |--------------------------------------------------------------------------
                */

                if (
                    ! empty($data['remarks'])
                ) {
                    $pickup->remarks =
                        $data['remarks'];
                }

                /*
                |--------------------------------------------------------------------------
                | Preferred pickup
                |--------------------------------------------------------------------------
                */

                if (
                    ! empty(
                        $data['preferred_pickup_at']
                    )
                ) {
                    $pickup->preferred_pickup_at =
                        $data['preferred_pickup_at'];
                }

                /*
                |--------------------------------------------------------------------------
                | Recalculate shipment count
                |--------------------------------------------------------------------------
                */

                $pickup->parcel_quantity =
                    $pickup
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