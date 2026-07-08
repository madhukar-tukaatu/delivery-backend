<?php

namespace Modules\Merchant\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantWebhook extends Model
{
    protected $guarded = [];

    protected $casts = [
        'events' => 'array',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }
}
