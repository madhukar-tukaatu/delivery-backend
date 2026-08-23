<?php

namespace Modules\Shipment\Services;

use Illuminate\Validation\ValidationException;
use Modules\Merchant\Models\Merchant;

final class MerchantShipmentGateService
{
    public function ensureCanCreateShipment(
        Merchant $merchant
    ): void {

        if ($merchant->status !== 'active') {
            throw ValidationException::withMessages([
                'merchant' =>
                    'Your merchant account is not active.',
            ]);
        }

        if (!$merchant->default_branch_id) {
            throw ValidationException::withMessages([
                'merchant' =>
                    'Your merchant account does not have an assigned pickup branch yet.',
            ]);
        }
    }
}