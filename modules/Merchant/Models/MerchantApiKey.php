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
        'is_active',
        'last_used_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
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
