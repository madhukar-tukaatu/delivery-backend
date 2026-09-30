<?php

namespace Modules\Setting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Models\PaymentGatewayAccount;
use Modules\Merchant\Models\Merchant;

/**
 * Partner marketplace (Tukaatu, FCA, ...). Each has its own API/callback base
 * and optional HamroPay HQ credentials via payment_gateway_accounts
 * (owner_type=marketplace). Stores (merchants) belong to a marketplace.
 */
class Marketplace extends Model
{
    protected $guarded = [];

    protected $hidden = [
        'callback_secret',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function setCallbackSecretAttribute(?string $value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['callback_secret'] = null;

            return;
        }

        $this->attributes['callback_secret'] = Crypt::encryptString($value);
    }

    public function getCallbackSecretAttribute($value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Throwable $e) {
            Log::warning('Marketplace callback_secret decrypt failed', [
                'id' => $this->attributes['id'] ?? null,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function merchants(): HasMany
    {
        return $this->hasMany(Merchant::class, 'marketplace_id');
    }

    public function hamroPayAccounts(): HasMany
    {
        return $this->hasMany(PaymentGatewayAccount::class, 'owner_id')
            ->where('owner_type', 'marketplace')
            ->where('gateway', 'hamropay');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public static function resolveFromMerchant(?Merchant $merchant): ?self
    {
        if (! $merchant) {
            return null;
        }

        if ($merchant->marketplace_id) {
            $row = static::query()->active()->find($merchant->marketplace_id);
            if ($row) {
                return $row;
            }
        }

        $platform = trim((string) ($merchant->external_platform ?? ''));
        if ($platform !== '') {
            $byCode = static::query()->active()->where('code', $platform)->first();
            if ($byCode) {
                return $byCode;
            }
        }

        return static::query()->active()->where('is_default', true)->orderBy('id')->first()
            ?: static::query()->active()->orderBy('id')->first();
    }
}