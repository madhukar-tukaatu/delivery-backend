<?php

namespace Modules\Shipment\Services;

use Illuminate\Validation\ValidationException;
use Modules\Merchant\Models\Merchant;

final class MerchantShipmentGateService
{
    public function ensureCanCreateShipment(
        Merchant $merchant
    ): void {

        if (! $merchant->isActive()) {
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

    /**
     * Normalize merchant dashboard / API payloads for ShipmentService::create.
     * Builds the gateway-style packet + receiver fields and fills pickup coords.
     */
    public function enrichShipmentPayload(Merchant $merchant, array $data): array
    {
        $pickupLocation = null;

        if (! empty($data['pickup_location_id'])) {
            $pickupLocation = $merchant->pickupLocations()
                ->where('id', $data['pickup_location_id'])
                ->where(function ($q) {
                    $q->where('status', 'active')->orWhereNull('status');
                })
                ->first();
        }

        if ($pickupLocation) {
            $data['pickup_name'] = $data['pickup_name'] ?? $pickupLocation->name;
            $data['pickup_phone'] = $data['pickup_phone'] ?? $pickupLocation->phone;
            $data['pickup_address'] = $data['pickup_address'] ?? $pickupLocation->address;
            $data['pickup_city'] = $data['pickup_city'] ?? $pickupLocation->city;
            $data['pickup_area'] = $data['pickup_area'] ?? $pickupLocation->area;
            $data['pickup_lat'] = $data['pickup_lat'] ?? $pickupLocation->latitude;
            $data['pickup_lng'] = $data['pickup_lng'] ?? $pickupLocation->longitude;
            $data['origin_branch_id'] = $data['origin_branch_id']
                ?? $pickupLocation->branch_id
                ?? $merchant->default_branch_id;
            $data['origin_sub_branch_id'] = $data['origin_sub_branch_id']
                ?? $pickupLocation->sub_branch_id
                ?? $merchant->default_sub_branch_id;
        } elseif (empty($data['pickup_lat']) || empty($data['pickup_lng'])) {
            $data['pickup_name'] = $data['pickup_name'] ?? $merchant->name;
            $data['pickup_phone'] = $data['pickup_phone'] ?? $merchant->phone;
            $data['pickup_address'] = $data['pickup_address']
                ?? $merchant->pickup_address
                ?? $merchant->address;
            $data['pickup_city'] = $data['pickup_city']
                ?? $merchant->pickup_city
                ?? $merchant->city;
            $data['pickup_area'] = $data['pickup_area']
                ?? $merchant->pickup_area
                ?? $merchant->area;
            $data['pickup_lat'] = $data['pickup_lat'] ?? $merchant->pickup_lat;
            $data['pickup_lng'] = $data['pickup_lng'] ?? $merchant->pickup_lng;
            $data['origin_branch_id'] = $data['origin_branch_id'] ?? $merchant->default_branch_id;
            $data['origin_sub_branch_id'] = $data['origin_sub_branch_id'] ?? $merchant->default_sub_branch_id;
        }

        $data['receiver_name'] = $data['receiver_name'] ?? $data['customer_name'] ?? null;
        $data['receiver_phone'] = $data['receiver_phone'] ?? $data['customer_phone'] ?? null;
        $data['receiver_email'] = $data['receiver_email'] ?? $data['customer_email'] ?? null;
        $data['delivery_address'] = $data['delivery_address'] ?? $data['customer_address'] ?? null;
        $data['delivery_city'] = $data['delivery_city'] ?? $data['customer_city'] ?? null;
        $data['delivery_area'] = $data['delivery_area'] ?? $data['customer_area'] ?? null;

        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $products = [];
        foreach ($items as $item) {
            if (! is_array($item) || empty($item['name'])) {
                continue;
            }
            $products[] = [
                'name' => $item['name'],
                'quantity' => (int) ($item['quantity'] ?? 1),
                'unit_price' => (float) ($item['value'] ?? $item['unit_price'] ?? 0),
            ];
        }

        if (empty($data['packet']) || ! is_array($data['packet'])) {
            $data['packet'] = [
                'parcel_type' => $data['parcel_type'] ?? 'product',
                'description' => $data['product_description'] ?? $data['package_description'] ?? null,
                'quantity' => (int) ($data['quantity'] ?? max(count($products), 1)),
                'weight' => (float) ($data['weight'] ?? $data['parcel_weight'] ?? $data['package_weight'] ?? 1),
                'declared_value' => (float) ($data['declared_value'] ?? $data['package_value'] ?? 0),
                'fragile' => (bool) ($data['fragile'] ?? false),
                'products' => $products,
            ];
        }

        $data['merchant_id'] = $data['merchant_id'] ?? $merchant->id;
        $data['delivery_charge_paid_by'] = $data['delivery_charge_paid_by'] ?? 'merchant';

        return $data;
    }
}
