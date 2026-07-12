<?php

namespace Modules\Merchant\Services;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Merchant\Models\MerchantApiKey;

class MerchantApiKeyGuard
{
    public function resolve(Request $request): MerchantApiKey
    {
        $apiKey = $request->header('X-Tukaatu-Api-Key');

        if (!$apiKey) {
            throw ValidationException::withMessages([
                'api_key' => 'X-Tukaatu-Api-Key header is required.',
            ]);
        }

        $hash = hash('sha256', $apiKey);

        $merchantKey = MerchantApiKey::query()
            ->where('api_key_hash', $hash)
            ->where('is_active', true)
            ->first();

        if (!$merchantKey) {
            throw ValidationException::withMessages([
                'api_key' => 'Invalid or inactive API key.',
            ]);
        }

        $merchantKey->forceFill([
            'last_used_at' => now(),
        ])->save();

        return $merchantKey;
    }
}