<?php

declare(strict_types=1);

namespace Modules\Merchant\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Merchant\Models\MerchantApiKey;

final class MerchantApiKeyGuard
{
    public function resolve(Request $request): MerchantApiKey
    {
        /*
        |--------------------------------------------------------------------------
        | Read credentials
        |--------------------------------------------------------------------------
        */

        $apiKey = trim((string) $request->header('X-Tukaatu-Key'));
        $apiSecret = trim((string) $request->header('X-Tukaatu-Secret'));

        /*
        |--------------------------------------------------------------------------
        | API Key required
        |--------------------------------------------------------------------------
        */

        if ($apiKey === '') {
            throw ValidationException::withMessages([
                'api_key' => 'X-Tukaatu-Key header is required.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | API Secret required
        |--------------------------------------------------------------------------
        */

        if ($apiSecret === '') {
            throw ValidationException::withMessages([
                'api_secret' => 'X-Tukaatu-Secret header is required.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Find API key
        |--------------------------------------------------------------------------
        |
        | Never trust merchant_id from request body.
        |
        */

        $apiKeyHash = hash(
            'sha256',
            $apiKey
        );

        $merchantKey = MerchantApiKey::query()
            ->where('api_key_hash', $apiKeyHash)
            ->where('is_active', true)
            ->first();

        if (!$merchantKey) {
            throw ValidationException::withMessages([
                'api_key' => 'Invalid or inactive API key.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Validate API secret
        |--------------------------------------------------------------------------
        |
        | Your database stores SHA-256 hash of the secret.
        |
        */

        $secretHash = hash(
            'sha256',
            $apiSecret
        );

        if (
            !hash_equals(
                (string) $merchantKey->api_secret_hash,
                $secretHash
            )
        ) {
            throw ValidationException::withMessages([
                'api_secret' => 'Invalid API credentials.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Merchant must be active
        |--------------------------------------------------------------------------
        */

        $merchant = $merchantKey->merchant;

        if (!$merchant) {
            throw ValidationException::withMessages([
                'api_key' => 'Merchant associated with API credentials was not found.',
            ]);
        }

        if (
            isset($merchant->status)
            && $merchant->status !== 'active'
        ) {
            throw ValidationException::withMessages([
                'api_key' => 'Merchant account is not active.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Update usage
        |--------------------------------------------------------------------------
        */

        $merchantKey->forceFill([
            'last_used_at' => now(),
        ])->save();

        /*
        |--------------------------------------------------------------------------
        | Return authenticated credential
        |--------------------------------------------------------------------------
        */

        return $merchantKey;
    }
}