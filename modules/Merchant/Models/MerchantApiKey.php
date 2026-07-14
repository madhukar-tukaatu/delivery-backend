<?php

namespace Modules\Merchant\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantApiKey extends Model
{
    protected $guarded = [];

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

    //  protected $fillable = [
    //     'merchant_id',
    //     'name',
    //     'api_key_hash',
    //     'is_active',
    //     'last_used_at',
    // ];

    // protected $casts = [
    //     'is_active' => 'boolean',
    //     'permissions' => 'array',
    //     'last_used_at' => 'datetime',
    //     'expires_at' => 'datetime',
    // ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }
}
