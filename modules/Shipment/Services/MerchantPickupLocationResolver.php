<?php

namespace Modules\Shipment\Services;

use Illuminate\Validation\ValidationException;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantPickupLocation;

class MerchantPickupLocationResolver
{
    public function resolve(Merchant $merchant, array $payload): ?MerchantPickupLocation
    {
        if (!empty($payload['self_drop'])) {
            return null;
        }

        $pickupLocationId = $payload['pickup_location_id'] ?? null;

        $query = MerchantPickupLocation::query()
            ->where('merchant_id', $merchant->id)
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhereIn('status', ['active', 'approved', 'pending']);
            });

        if ($pickupLocationId) {
            $pickupLocation = (clone $query)->where('id', $pickupLocationId)->first();

            if (!$pickupLocation) {
                throw ValidationException::withMessages([
                    'pickup_location_id' => 'Selected pickup location is invalid for this merchant.',
                ]);
            }

            return $pickupLocation;
        }

        $pickupLocation = (clone $query)
            ->orderByDesc('is_default')
            ->orderByRaw('CASE WHEN latitude IS NOT NULL AND longitude IS NOT NULL THEN 0 ELSE 1 END')
            ->orderBy('id')
            ->first();

        if (!$pickupLocation) {
            throw ValidationException::withMessages([
                'pickup_location_id' => 'No active pickup location found for this merchant. Please complete onboarding first.',
            ]);
        }

        return $pickupLocation;
    }
}
