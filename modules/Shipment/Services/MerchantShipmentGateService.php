<?php

namespace Modules\Shipment\Services;

use Illuminate\Validation\ValidationException;
use Modules\Merchant\Models\Merchant;

class MerchantShipmentGateService
{
    public function ensureCanCreateShipment(Merchant $merchant): void
    {
        if ($merchant->status !== 'active') {
            throw ValidationException::withMessages([
                'merchant' => 'Your merchant account is not verified yet. Please complete onboarding and wait for approval.',
            ]);
        }

        if (!$merchant->default_branch_id) {
            throw ValidationException::withMessages([
                'merchant' => 'Your merchant account does not have an assigned pickup branch yet.',
            ]);
        }
    }

    public function enrichShipmentPayload(Merchant $merchant, array $data): array
    {
        $pickupLocation = null;

        if (!empty($data['pickup_location_id'])) {
            $pickupLocation = $merchant->pickupLocations()
                ->where('id', $data['pickup_location_id'])
                ->where('status', 'active')
                ->first();
        }

        if ($pickupLocation) {
            $data['pickup_name'] = $pickupLocation->name;
            $data['pickup_phone'] = $pickupLocation->phone;
            $data['pickup_address'] = $pickupLocation->address;
            $data['pickup_city'] = $pickupLocation->city;
            $data['pickup_area'] = $pickupLocation->area;
            $data['pickup_lat'] = $pickupLocation->latitude;
            $data['pickup_lng'] = $pickupLocation->longitude;
            $data['origin_branch_id'] = $pickupLocation->branch_id ?: $merchant->default_branch_id;
            $data['origin_sub_branch_id'] = $pickupLocation->sub_branch_id ?: $merchant->default_sub_branch_id;
            $data['manual_branch_override'] = true;

            return $data;
        }

        if (empty($data['pickup_lat']) || empty($data['pickup_lng'])) {
            $data['pickup_name'] = $merchant->name;
            $data['pickup_phone'] = $merchant->phone;
            $data['pickup_address'] = $merchant->pickup_address ?: $merchant->address;
            $data['pickup_city'] = $merchant->pickup_city ?: $merchant->city;
            $data['pickup_area'] = $merchant->pickup_area ?: $merchant->area;
            $data['pickup_lat'] = $merchant->pickup_lat;
            $data['pickup_lng'] = $merchant->pickup_lng;
            $data['origin_branch_id'] = $merchant->default_branch_id;
            $data['origin_sub_branch_id'] = $merchant->default_sub_branch_id;
            $data['manual_branch_override'] = true;
        }

        return $data;
    }
}
