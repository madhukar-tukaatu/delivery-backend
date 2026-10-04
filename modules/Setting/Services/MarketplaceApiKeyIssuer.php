<?php

declare(strict_types=1);

namespace Modules\Setting\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Setting\Models\Marketplace;
use RuntimeException;
use Throwable;

/**
 * Issues marketplace API keys that partners use to call Express,
 * and that Express can send back outbound (POD) when key_encrypted is set.
 *
 * Public key: hashed (key_hash) + optionally encrypted (key_encrypted).
 * Secret: always encrypted (secret_encrypted) for HMAC verify and outbound.
 */
final class MarketplaceApiKeyIssuer
{
    /**
     * Create a new active key for the marketplace. Revokes previous active keys.
     *
     * @return array{api_key_id: int, key_prefix: string, public_key: string, secret: string, revoked_ids: list<int>}
     */
    public function reissue(Marketplace $marketplace, string $environment = 'test', ?string $name = null): array
    {
        $environment = strtolower(trim($environment));
        if (! in_array($environment, ['test', 'live'], true)) {
            throw new RuntimeException('Environment must be test or live.');
        }

        $publicPrefix = $environment === 'live' ? 'mkp_live_' : 'mkp_test_';
        $secretPrefix = $environment === 'live' ? 'mks_live_' : 'mks_test_';

        do {
            $publicKey = $publicPrefix.Str::lower(Str::random(48));
            $keyHash = hash('sha256', $publicKey);
            $exists = DB::table('marketplace_api_keys')->where('key_hash', $keyHash)->exists();
        } while ($exists);

        $keyPrefix = substr($publicKey, 0, 20);
        $secret = $secretPrefix.Str::random(64);
        $label = $name !== null && trim($name) !== ''
            ? trim($name)
            : trim((string) $marketplace->name).' '.$environment.' key';

        try {
            return DB::transaction(function () use (
                $marketplace,
                $environment,
                $publicKey,
                $keyHash,
                $keyPrefix,
                $secret,
                $label
            ): array {
                $revokedIds = [];
                $activeQuery = DB::table('marketplace_api_keys')
                    ->where('marketplace_id', $marketplace->id)
                    ->where('is_active', true)
                    ->where(function ($q): void {
                        $q->whereNull('revoked_at')->orWhere('revoked_at', '>', now());
                    });

                $activeIds = $activeQuery->lockForUpdate()->pluck('id')->all();
                if ($activeIds !== []) {
                    DB::table('marketplace_api_keys')
                        ->whereIn('id', $activeIds)
                        ->update([
                            'is_active' => false,
                            'revoked_at' => now(),
                            'updated_at' => now(),
                        ]);
                    $revokedIds = array_map('intval', $activeIds);
                }

                $row = [
                    'marketplace_id' => (int) $marketplace->id,
                    'key_prefix' => $keyPrefix,
                    'key_hash' => $keyHash,
                    'secret_encrypted' => Crypt::encryptString($secret),
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (Schema::hasColumn('marketplace_api_keys', 'key_encrypted')) {
                    $row['key_encrypted'] = Crypt::encryptString($publicKey);
                }

                if (Schema::hasColumn('marketplace_api_keys', 'name')) {
                    $row['name'] = $label;
                }

                if (Schema::hasColumn('marketplace_api_keys', 'environment')) {
                    $row['environment'] = $environment;
                }

                if (Schema::hasColumn('marketplace_api_keys', 'revoked_at')) {
                    $row['revoked_at'] = null;
                }

                if (Schema::hasColumn('marketplace_api_keys', 'last_used_at')) {
                    $row['last_used_at'] = null;
                }

                if (Schema::hasColumn('marketplace_api_keys', 'expires_at')) {
                    $row['expires_at'] = null;
                }

                if (Schema::hasColumn('marketplace_api_keys', 'scopes')) {
                    $row['scopes'] = null;
                }

                $apiKeyId = (int) DB::table('marketplace_api_keys')->insertGetId($row);

                return [
                    'api_key_id' => $apiKeyId,
                    'key_prefix' => $keyPrefix,
                    'public_key' => $publicKey,
                    'secret' => $secret,
                    'revoked_ids' => $revokedIds,
                ];
            }, 3);
        } catch (Throwable $e) {
            report($e);
            throw new RuntimeException('Could not reissue marketplace API key: '.$e->getMessage(), 0, $e);
        }
    }

    public const RIDER_SETUP_MESSAGE = 'Online payment is not set up for this marketplace yet. An admin must reissue the marketplace API key.';

    /**
     * Active outbound credentials for POD (and similar Express -> marketplace calls).
     *
     * @return array{ok: bool, api_key: string, api_secret: string, key_prefix: string, api_key_id: ?int, error: ?string, code: ?string, rider_error: ?string}
     */
    public function resolveOutboundCredentials(int $marketplaceId, string $marketplaceLabel = 'this marketplace'): array
    {
        $empty = [
            'ok' => false,
            'api_key' => '',
            'api_secret' => '',
            'key_prefix' => '',
            'api_key_id' => null,
            'error' => null,
            'code' => null,
            'rider_error' => null,
        ];

        $query = DB::table('marketplace_api_keys')
            ->where('marketplace_id', $marketplaceId)
            ->where('is_active', true)
            ->orderByDesc('id');

        if (Schema::hasColumn('marketplace_api_keys', 'revoked_at')) {
            $query->where(function ($q): void {
                $q->whereNull('revoked_at');
            });
        }

        if (Schema::hasColumn('marketplace_api_keys', 'expires_at')) {
            $query->where(function ($q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
        }

        $row = $query->first();
        if (! $row) {
            $empty['code'] = 'no_active_key';
            $empty['rider_error'] = self::RIDER_SETUP_MESSAGE;
            $empty['error'] = 'Marketplace '.$marketplaceLabel.' has no active issued API key. Open Admin -> Marketplaces and use Reissue API key, then give the new key and secret to that marketplace partner.';

            return $empty;
        }

        $keyPrefix = (string) ($row->key_prefix ?? '');
        $empty['key_prefix'] = $keyPrefix;
        $empty['api_key_id'] = (int) $row->id;

        $keyEncrypted = Schema::hasColumn('marketplace_api_keys', 'key_encrypted')
            ? trim((string) ($row->key_encrypted ?? ''))
            : '';

        if ($keyEncrypted === '') {
            $empty['code'] = 'missing_key_encrypted';
            $empty['rider_error'] = self::RIDER_SETUP_MESSAGE;
            $empty['error'] = 'Marketplace '.$marketplaceLabel.' issued key '.$keyPrefix.'... cannot be sent outbound (key_encrypted missing). Reissue the API key on Admin -> Marketplaces so Express can store the full key encrypted, then share the new key and secret with the marketplace partner.';

            return $empty;
        }

        try {
            $publicKey = Crypt::decryptString($keyEncrypted);
            $secret = Crypt::decryptString((string) $row->secret_encrypted);
        } catch (Throwable $e) {
            report($e);
            $empty['code'] = 'decrypt_failed';
            $empty['rider_error'] = self::RIDER_SETUP_MESSAGE;
            $empty['error'] = 'Marketplace '.$marketplaceLabel.' issued key '.$keyPrefix.'... could not be decrypted. Reissue the API key on Admin -> Marketplaces.';

            return $empty;
        }

        $publicKey = trim((string) $publicKey);
        $secret = trim((string) $secret);
        if ($publicKey === '' || $secret === '') {
            $empty['code'] = 'empty_after_decrypt';
            $empty['rider_error'] = self::RIDER_SETUP_MESSAGE;
            $empty['error'] = 'Marketplace '.$marketplaceLabel.' issued credentials are empty after decrypt. Reissue the API key on Admin -> Marketplaces.';

            return $empty;
        }

        return [
            'ok' => true,
            'api_key' => $publicKey,
            'api_secret' => $secret,
            'key_prefix' => $keyPrefix,
            'api_key_id' => (int) $row->id,
            'error' => null,
            'code' => null,
            'rider_error' => null,
        ];
    }

    /**
     * Safe summary of the active issued key for admin UI (never includes secrets).
     *
     * @return array{id: int, name: ?string, key_prefix: string, has_key_encrypted: bool, is_active: bool, last_used_at: mixed, created_at: mixed}|null
     */
    public function presentActiveKey(int $marketplaceId): ?array
    {
        $query = DB::table('marketplace_api_keys')
            ->where('marketplace_id', $marketplaceId)
            ->where('is_active', true)
            ->orderByDesc('id');

        if (Schema::hasColumn('marketplace_api_keys', 'revoked_at')) {
            $query->whereNull('revoked_at');
        }

        $row = $query->first();
        if (! $row) {
            return null;
        }

        $hasEncrypted = Schema::hasColumn('marketplace_api_keys', 'key_encrypted')
            && filled($row->key_encrypted ?? null);

        return [
            'id' => (int) $row->id,
            'name' => isset($row->name) ? (string) $row->name : null,
            'key_prefix' => (string) ($row->key_prefix ?? ''),
            'has_key_encrypted' => $hasEncrypted,
            'can_outbound_pod' => $hasEncrypted,
            'is_active' => (bool) $row->is_active,
            'last_used_at' => $row->last_used_at ?? null,
            'created_at' => $row->created_at ?? null,
        ];
    }
}