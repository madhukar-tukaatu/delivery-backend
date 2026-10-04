<?php

namespace Modules\Settlement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Merchant\Models\Merchant;

class MerchantSettlement extends Model
{
    protected $guarded = [];

    protected $casts = [
        'period_from' => 'date',
        'period_to' => 'date',
        'settled_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(MerchantSettlementItem::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
