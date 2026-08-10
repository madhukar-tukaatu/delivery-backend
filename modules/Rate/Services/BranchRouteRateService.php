<?php

declare(strict_types=1);

namespace Modules\Rate\Services;

use Illuminate\Validation\ValidationException;
use Modules\Rate\Models\BranchRouteRate;

class BranchRouteRateService
{
    /**
     * Resolve the active branch pricing rate.
     *
     * Example:
     *
     * KTM 185 -> Kavre 190
     *
     * searches:
     *
     * pickup_branch_id  = 185
     * delivery_branch_id = 190
     */
    public function resolve(
        int $pickupBranchId,
        int $deliveryBranchId
    ): BranchRouteRate {
        /*
         * ----------------------------------------------------------
         * Direct branch pricing
         * ----------------------------------------------------------
         */
        $directRate = BranchRouteRate::query()
            ->active()
            ->where('pickup_branch_id', $pickupBranchId)
            ->where('delivery_branch_id', $deliveryBranchId)
            ->latest('id')
            ->first();

        if ($directRate) {
            return $directRate;
        }

        /*
         * ----------------------------------------------------------
         * No direct rate found
         * ----------------------------------------------------------
         */
        throw ValidationException::withMessages([
            'route' => [
                sprintf(
                    'No active branch pricing rate is configured from branch %d to branch %d.',
                    $pickupBranchId,
                    $deliveryBranchId
                ),
            ],
        ]);
    }
}