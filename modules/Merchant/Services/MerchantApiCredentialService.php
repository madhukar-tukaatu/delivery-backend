<?php

namespace Modules\Merchant\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantApiKey;

class MerchantApiCredentialService
{
    /**
     * Field names in this service must match your existing merchant_api_keys table.
     * Keep this service as the single credential-generation point.
     */
    public function issuePrimaryCredential(Merchant $merchant, array $abilities): array
    {
        $existing = MerchantApiKey::query()
            ->where('merchant_id', $merchant->id)
            ->where('is_active', true)
            ->where('status', 'active')
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return [
                'created' => false,
                'credential' => $existing,
                'api_key' => null,
                'api_secret' => null,
            ];
        }

        $plainApiKey = 'tkt_live_' . Str::random(40);
        $plainApiSecret = 'tks_' . Str::random(64);

        $credential = MerchantApiKey::query()->create([
            'merchant_id' => $merchant->id,
            'name' => 'Primary Store Integration',
            'api_key' => $plainApiKey,
            'api_key_hash' => hash('sha256', $plainApiKey),
            'api_secret_hash' => hash('sha256', $plainApiSecret),
            'api_secret_encrypted' => Crypt::encryptString($plainApiSecret),
            'abilities' => array_values($abilities),
            'status' => 'active',
            'is_active' => true,
            'secret_revealed_at' => now(),
        ]);

        return [
            'created' => true,
            'credential' => $credential,
            'api_key' => $plainApiKey,
            'api_secret' => $plainApiSecret,
        ];
    }
}
