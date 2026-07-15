<?php

namespace Modules\Merchant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantApiKey extends Model
{
    protected $fillable = [
        'merchant_id',
        'name',
        'api_key',
        'api_key_hash',
        'api_secret_hash',
        'api_secret_encrypted',
        'environment',
        'permissions',
        'last_used_at',
        'expires_at',
        'secret_revealed_at',
        'status',
        'is_active',
    ];

    protected $casts = [
        'permissions' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'secret_revealed_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    protected $hidden = [
        'api_secret_hash',
        'api_secret_encrypted',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}