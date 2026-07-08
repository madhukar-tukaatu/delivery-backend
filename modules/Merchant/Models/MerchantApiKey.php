<?php

namespace Modules\Merchant\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantApiKey extends Model
{
    protected $guarded = [];

    protected $casts = [
        'permissions' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }
}
