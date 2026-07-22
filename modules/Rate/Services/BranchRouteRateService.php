<?php

namespace Modules\Rate\Services;

use Modules\Rate\Models\BranchRouteRate;
use Illuminate\Validation\ValidationException;

class BranchRouteRateService
{
    public function resolve(
        int $originBranchId,
        int $destinationBranchId
    ): BranchRouteRate {
        $directRate = BranchRouteRate::query()
            ->active()
            ->where('origin_branch_id', $originBranchId)
            ->where(
                'destination_branch_id',
                $destinationBranchId
            )
            ->latest('effective_from')
            ->first();

        if ($directRate) {
            return $directRate;
        }

        $reverseRate = BranchRouteRate::query()
            ->active()
            ->where('origin_branch_id', $destinationBranchId)
            ->where(
                'destination_branch_id',
                $originBranchId
            )
            ->where('bidirectional', true)
            ->latest('effective_from')
            ->first();

        if ($reverseRate) {
            return $reverseRate;
        }

        throw ValidationException::withMessages([
            'route' => [
                'No active pricing rate has been configured for this branch route.',
            ],
        ]);
    }
}