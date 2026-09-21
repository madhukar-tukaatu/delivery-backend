<?php

namespace Modules\Billing\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Models\PaymentGatewayAccount;
use Modules\Billing\Support\PaymentGatewayCatalog;

class PaymentGatewayAccountService
{
    public const GATEWAYS = ['hamropay', 'esewa', 'khalti', 'connectips'];

    public function companyAccount(string $gateway = 'hamropay'): ?PaymentGatewayAccount
    {
        return PaymentGatewayAccount::query()
            ->forCompany()
            ->gateway($gateway)
            ->where('is_enabled', true)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->first();
    }

    public function branchAccount(int $branchId, string $gateway = 'hamropay'): ?PaymentGatewayAccount
    {
        return PaymentGatewayAccount::query()
            ->forBranch($branchId)
            ->gateway($gateway)
            ->where('is_enabled', true)
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Branch account preferred; company account as fallback for ops bootstrap.
     */
    public function resolvePayerAccount(?int $branchId, string $gateway = 'hamropay'): ?PaymentGatewayAccount
    {
        if ($branchId) {
            $branch = $this->branchAccount($branchId, $gateway);
            if ($branch) {
                return $branch;
            }
        }

        return $this->companyAccount($gateway);
    }

    /**
     * Keep only known keys for the gateway (ignore unknown FE extras).
     */
    public function filterCredentials(string $gateway, array $credentials): array
    {
        $allowed = PaymentGatewayCatalog::fieldKeys($gateway);
        if ($allowed === []) {
            return $credentials;
        }

        $out = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $credentials)) {
                $out[$key] = $credentials[$key];
            }
        }

        return $out;
    }

    public function upsert(string $ownerType, ?int $ownerId, string $gateway, array $credentials, array $opts = []): PaymentGatewayAccount
    {
        $gateway = strtolower($gateway);
        if (! in_array($gateway, self::GATEWAYS, true)) {
            throw ValidationException::withMessages(['gateway' => ['Unsupported gateway.']]);
        }
        if (! in_array($ownerType, ['company', 'branch'], true)) {
            throw ValidationException::withMessages(['owner_type' => ['Must be company or branch.']]);
        }
        if ($ownerType === 'branch' && ! $ownerId) {
            throw ValidationException::withMessages(['owner_id' => ['Branch id required.']]);
        }
        if ($ownerType === 'company') {
            $ownerId = null;
        }

        $label = $opts['label'] ?? 'default';

        $account = PaymentGatewayAccount::query()->firstOrNew([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'gateway' => $gateway,
            'label' => $label,
        ]);

        // Merge credentials so blank secret fields keep previous values
        $merged = $account->exists ? $account->credentials : [];
        foreach ($credentials as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $merged[$k] = $v;
        }

        $account->credentials = $merged;
        $account->is_enabled = array_key_exists('is_enabled', $opts)
            ? (bool) $opts['is_enabled']
            : ($account->is_enabled ?? true);
        $account->is_default = array_key_exists('is_default', $opts)
            ? (bool) $opts['is_default']
            : ($account->is_default ?? true);
        $account->meta = $opts['meta'] ?? $account->meta;
        $account->save();

        if ($account->is_default) {
            PaymentGatewayAccount::query()
                ->where('owner_type', $ownerType)
                ->where('owner_id', $ownerId)
                ->where('gateway', $gateway)
                ->where('id', '!=', $account->id)
                ->update(['is_default' => false]);
        }

        return $account->fresh();
    }

    public function hamroPayClientFromAccount(?PaymentGatewayAccount $account): HamroPayService
    {
        $base = app(HamroPayService::class);
        if (! $account) {
            return $base;
        }

        $c = $account->credentials;

        return $base->forMerchant([
            'merchant_id' => $c['merchant_id'] ?? $c['hamropay_merchant_id'] ?? null,
            'client_id' => $c['client_id'] ?? null,
            'api_key' => $c['client_api_key'] ?? $c['api_key'] ?? null,
            'secret_key' => $c['secret'] ?? $c['secret_key'] ?? null,
        ])->withEndpoints(
            $c['api_base_url'] ?? null,
            $c['gateway_url'] ?? null,
            isset($c['verify_ssl']) ? (bool) $c['verify_ssl'] : null,
        );
    }

    public function masked(PaymentGatewayAccount $account): array
    {
        $creds = $account->credentials;
        $masked = [];
        foreach ($creds as $k => $v) {
            $isSecret = str_contains(strtolower($k), 'secret')
                || str_contains(strtolower($k), 'key')
                || str_contains(strtolower($k), 'password')
                || str_contains(strtolower($k), 'authorization');
            if ($isSecret && filled($v)) {
                $s = (string) $v;
                $masked[$k] = [
                    'set' => true,
                    'masked' => strlen($s) <= 4 ? '****' : str_repeat('*', max(0, strlen($s) - 4)).substr($s, -4),
                ];
            } else {
                $masked[$k] = ['set' => filled($v), 'value' => $v];
            }
        }

        return [
            'id' => $account->id,
            'owner_type' => $account->owner_type,
            'owner_id' => $account->owner_id,
            'gateway' => $account->gateway,
            'label' => $account->label,
            'is_enabled' => $account->is_enabled,
            'is_default' => $account->is_default,
            'credentials' => $masked,
            'updated_at' => $account->updated_at,
        ];
    }

    public function logDecryptFailure(int $accountId, \Throwable $e): void
    {
        Log::warning('PaymentGatewayAccount decrypt failed', [
            'id' => $accountId,
            'message' => $e->getMessage(),
        ]);
    }
}
