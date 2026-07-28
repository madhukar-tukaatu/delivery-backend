<?php

namespace Modules\Rate\Services\Pricing;

use Illuminate\Validation\ValidationException;
use Modules\Rate\Models\PricingSetting;

final class ActivePricingSettingsService
{
    public function active(): PricingSetting
    {
        $settings = PricingSetting::query()
            ->global()
            ->active()
            ->latest('id')
            ->first();

        if (!$settings) {
            throw ValidationException::withMessages([
                'pricing_settings' => [
                    'No active global pricing-settings version is configured.',
                ],
            ]);
        }

        return $settings;
    }
}
