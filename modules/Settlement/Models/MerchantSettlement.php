<?php

namespace Modules\Settlement\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantSettlement extends Model
{
    protected $guarded = [];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
        'settled_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(MerchantSettlementItem::class);
    }
}
