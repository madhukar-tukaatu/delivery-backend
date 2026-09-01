<?php

declare(strict_types=1);

namespace Modules\Shipment\Services;

use Illuminate\Validation\ValidationException;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantPickupLocation;

final class MerchantPickupLocationResolver
{
    /**
     * Resolve merchant pickup location.
     *
     * Priority:
     *
     * 1. Explicit pickup location
     * 2. Default pickup location
     * 3. First active pickup location
     *
     * Self-drop:
     *
     * - returns null
     * - shipment will not enter a pickup batch
     */
    public function resolve(
        Merchant $merchant,
        array $payload
    ): ?MerchantPickupLocation {

        /*
        |--------------------------------------------------------------------------
        | SELF DROP
        |--------------------------------------------------------------------------
        */

        if (
            filter_var(
                $payload['self_drop'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            )
        ) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | MERCHANT OWNED LOCATIONS
        |--------------------------------------------------------------------------
        */

        $query =
            MerchantPickupLocation::query()
                ->where(
                    'merchant_id',
                    $merchant->id
                )
                ->where(function ($query): void {
                    $query
                        ->whereNull('status')
                        ->orWhereIn(
                            'status',
                            [
                                'active',
                                'approved',
                            ]
                        );
                });

        /*
        |--------------------------------------------------------------------------
        | EXPLICIT LOCATION
        |--------------------------------------------------------------------------
        */

        if (
            ! empty(
                $payload['pickup_location_id']
            )
        ) {
            $pickupLocation =
                (clone $query)
                    ->where(
                        'id',
                        $payload['pickup_location_id']
                    )
                    ->first();

            if (! $pickupLocation) {
                throw ValidationException::withMessages([
                    'pickup_location_id' => [
                        'Selected pickup location is invalid for this merchant.',
                    ],
                ]);
            }

            return $pickupLocation;
        }

        /*
        |--------------------------------------------------------------------------
        | DEFAULT LOCATION
        |--------------------------------------------------------------------------
        */

        $pickupLocation =
            (clone $query)
                ->orderByDesc('is_default')
                ->orderByRaw(
                    'CASE
                        WHEN latitude IS NOT NULL
                        AND longitude IS NOT NULL
                        THEN 0
                        ELSE 1
                    END'
                )
                ->orderBy('id')
                ->first();

        if (! $pickupLocation) {
            throw ValidationException::withMessages([
                'pickup_location_id' => [
                    'No active pickup location found for this merchant.',
                ],
            ]);
        }

        return $pickupLocation;
    }
}