<?php

namespace Modules\Rate\Services\Pricing;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

final class ActivePricingSettingsService
{
    public function active(): stdClass
    {
        $settings = DB::table('pricing_settings')
            ->where('is_active', true)
            ->where(function ($query): void {
                $query
                    ->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', now());
            })
            ->where(function ($query): void {
                $query
                    ->whereNull('effective_until')
                    ->orWhere('effective_until', '>', now());
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        if (!$settings) {
            throw ValidationException::withMessages([
                'pricing_settings' => [
                    'No active pricing-settings version is configured.',
                ],
            ]);
        }

        return $settings;
    }
}
