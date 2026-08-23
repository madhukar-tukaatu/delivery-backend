<?php

namespace Modules\Shipment\Services;

use Illuminate\Validation\ValidationException;
use Modules\Merchant\Models\Merchant;

class MerchantShipmentGateService
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
                    'No pickup branch has been assigned to your merchant account.',
            ]);
        }
    }

    public function ensureShipmentOwnership(
        Merchant $merchant,
        object $shipment
    ): void {
        if ((int) $shipment->merchant_id !== (int) $merchant->id) {
            throw ValidationException::withMessages([
                'shipment_ids' =>
                    'One or more shipments do not belong to this merchant.',
            ]);
        }
    }
}