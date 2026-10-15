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

        $apiKey = trim((string) (
            $request->header('X-Tukaatu-Key')
            ?: $request->header('X-API-KEY')
            ?: $request->bearerToken()
        ));
        $apiSecret = trim((string) $request->header('X-Tukaatu-Secret'));

        /*
        |--------------------------------------------------------------------------
        | API Key required
        |--------------------------------------------------------------------------
        */

        if ($apiKey === '') {
            throw ValidationException::withMessages([
                'api_key' => 'API Key is required (pass X-API-KEY or X-Tukaatu-Key header).',
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
            ->where(function ($q) use ($apiKey, $apiKeyHash) {
                $q->where('api_key_hash', $apiKeyHash)
                  ->orWhere('api_key', $apiKey);
            })
            ->where('is_active', true)
            ->first();

        if (!$merchantKey) {
            throw ValidationException::withMessages([
                'api_key' => 'Invalid or inactive API key.',
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Validate API secret (if provided or required by key)
        |--------------------------------------------------------------------------
        */

        if ($apiSecret !== '') {
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
        } elseif (!empty($merchantKey->api_secret_hash) && !$request->hasHeader('X-API-KEY')) {
            throw ValidationException::withMessages([
                'api_secret' => 'X-Tukaatu-Secret header is required.',
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

        if (! $merchant->isActive()) {
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