<?php

namespace Modules\Rate\Services;

use Illuminate\Support\Facades\Cache;

final class PricingCacheService
{
    public function forgetSettings(): void
    {
        Cache::store('redis')->forget('pricing:active-settings');
    }

    public function forgetServiceType(string $code): void
    {
        Cache::store('redis')->forget(
            'pricing:service-type:' . strtolower(trim($code))
        );
    }

    public function forgetRoute(int $pickupBranchId, int $deliveryBranchId): void
    {
        Cache::store('redis')->forget(
            "pricing:route:{$pickupBranchId}:{$deliveryBranchId}"
        );

        Cache::store('redis')->forget(
            "pricing:route:{$deliveryBranchId}:{$pickupBranchId}"
        );
    }

    public function forgetBranchResolution(): void
    {
        Cache::store('redis')->forget('pricing:main-branches');
    }
}
