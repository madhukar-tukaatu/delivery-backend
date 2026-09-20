<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class PaymentGatewayAccount extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_enabled' => 'boolean',
        'is_default' => 'boolean',
        'meta' => 'array',
    ];

    protected $hidden = [
        'credentials',
    ];

    public function setCredentialsAttribute(?array $value): void
    {
        if ($value === null) {
            $this->attributes['credentials'] = null;

            return;
        }

        $this->attributes['credentials'] = Crypt::encryptString(json_encode($value));
    }

    public function getCredentialsAttribute($value): array
    {
        if (! $value) {
            return [];
        }

        try {
            $decoded = json_decode(Crypt::decryptString($value), true);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function credential(string $key, mixed $default = null): mixed
    {
        return $this->credentials[$key] ?? $default;
    }

    public function scopeForCompany($query)
    {
        return $query->where('owner_type', 'company')->whereNull('owner_id');
    }

    public function scopeForBranch($query, int $branchId)
    {
        return $query->where('owner_type', 'branch')->where('owner_id', $branchId);
    }

    public function scopeGateway($query, string $gateway)
    {
        return $query->where('gateway', strtolower($gateway));
    }
}
