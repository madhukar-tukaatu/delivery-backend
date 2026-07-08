<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class OperationalCacheService
{
    public function activeBranches()
    {
        return Cache::remember('branches:active', now()->addMinutes(30), function () {
            return \Modules\Branch\Models\Branch::where('is_active', true)->get();
        });
    }

    public function forgetBranches(): void
    {
        Cache::forget('branches:active');
    }

    public function activeRateCards()
    {
        return Cache::remember('rate_cards:active', now()->addMinutes(30), function () {
            if (!class_exists('\Modules\Rate\Models\RateCard')) {
                return collect();
            }

            return \Modules\Rate\Models\RateCard::where('is_active', true)->get();
        });
    }

    public function forgetRates(): void
    {
        Cache::forget('rate_cards:active');
    }
}
