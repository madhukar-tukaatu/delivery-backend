<?php

namespace Modules\Setting\Services;

use Modules\Merchant\Models\Merchant;
use Modules\Setting\Models\Marketplace;
use Modules\Shipment\Models\Shipment;

/**
 * Payment API host for a store.
 *
 * Admin -> Marketplaces stores one api_base_url per marketplace
 * (tukaatu.com -> https://api.tukaatu.com, FCA -> https://api.fca.com.np, or any other).
 * Callers must not use a single global payment URL.
 */
class MarketplacePaymentUrlResolver
{
    /**
     * @return array{marketplace: ?Marketplace, api_base_url: string, payment_request_url: string}
     */
    public function resolve(?Shipment $shipment = null, ?Merchant $merchant = null): array
    {
        if (! $merchant && $shipment) {
            $shipment->loadMissing('merchant');
            $merchant = $shipment->merchant;
        }

        $marketplace = Marketplace::resolveForShipment($shipment, $merchant);
        $base = rtrim(trim((string) ($marketplace?->api_base_url ?? '')), '/');

        return [
            'marketplace' => $marketplace,
            'api_base_url' => $base,
            'payment_request_url' => $base !== '' && $marketplace
                ? $marketplace->podPaymentRequestUrl()
                : '',
        ];
    }

    public function baseUrl(?Shipment $shipment = null, ?Merchant $merchant = null): string
    {
        return $this->resolve($shipment, $merchant)['api_base_url'];
    }
}
