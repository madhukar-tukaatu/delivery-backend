<?php

namespace Modules\Rate\Services\Pricing;

use Illuminate\Validation\ValidationException;
use Modules\Rate\Models\PricingSetting;

final class PricingSettingsResolverService
{
    public function resolve(
        ?int $branchTransferRouteId
    ): array {
        if (
            $branchTransferRouteId !== null &&
            $branchTransferRouteId > 0
        ) {
            $custom = PricingSetting::query()
                ->where(
                    'scope_type',
                    'transfer_route'
                )
                ->where(
                    'branch_transfer_route_id',
                    $branchTransferRouteId
                )
                ->where('is_active', true)
                ->latest('id')
                ->first();

            if ($custom) {
                return [
                    'settings' => $custom,
                    'source' => 'transfer_route',
                    'is_fallback' => false,
                ];
            }
        }

        $global = PricingSetting::query()
            ->where('scope_type', 'global')
            ->whereNull('branch_transfer_route_id')
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
            'is_fallback' => true,
        ];
    }
}