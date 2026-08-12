<?php

namespace Modules\Rate\Services\Pricing;

use Illuminate\Validation\ValidationException;
use Modules\Rate\Models\PricingSetting;

final class PricingSettingsResolverService
{
    public function resolve(
        ?int $branchTransferRouteId
    ): array {
        $global = PricingSetting::query()
            ->where('is_active', true)
            ->latest('id')
            ->first();

        if (!$global) {
            throw ValidationException::withMessages([
                'pricing_settings' => [
                    'No active global pricing settings are configured.',
                ],
            ]);
        }

        return [
            'settings' => $global,
            'source' => 'global',
            'is_fallback' => false,
        ];
    }
}