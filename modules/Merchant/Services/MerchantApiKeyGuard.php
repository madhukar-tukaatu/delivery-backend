<?php

namespace Modules\Merchant\Services;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Merchant\Models\MerchantApiKey;

class MerchantApiKeyGuard
{
    public function resolve(Request $request): MerchantApiKey
    {
        /*
        |--------------------------------------------------------------------------
        | Read credentials
        |--------------------------------------------------------------------------
        */

        $apiKey = trim((string) $request->header('X-Tukaatu-Key'));
        $secret = trim((string) $request->header('X-Tukaatu-Secret'));

        /*
        |--------------------------------------------------------------------------
        | API key required
        |--------------------------------------------------------------------------
        */

        if ($apiKey === '') {
            throw ValidationException::withMessages([
                'api_key' => 'X-Tukaatu-Key header is required.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | API secret required
        |--------------------------------------------------------------------------
        */

        if ($secret === '') {
            throw ValidationException::withMessages([
                'api_secret' => 'X-Tukaatu-Secret header is required.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Hash API key
        |--------------------------------------------------------------------------
        */

        $apiKeyHash = hash('sha256', $apiKey);

        /*
        |--------------------------------------------------------------------------
        | Find active credential
        |--------------------------------------------------------------------------
        */

        $merchantKey = MerchantApiKey::query()
            ->where('api_key_hash', $apiKeyHash)
            ->where('is_active', true)
            ->first();

        if (!$merchantKey) {
            throw ValidationException::withMessages([
                'api_key' => 'Invalid or inactive API credentials.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Verify secret
        |--------------------------------------------------------------------------
        */

        if (!hash_equals(
            (string) $merchantKey->secret_hash,
            hash('sha256', $secret)
        )) {
            throw ValidationException::withMessages([
                'api_secret' => 'Invalid API credentials.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Update last used
        |--------------------------------------------------------------------------
        */

        $merchantKey->forceFill([
            'last_used_at' => now(),
        ])->save();

        return $merchantKey;
    }
}