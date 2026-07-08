<?php

namespace Modules\Rate\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Merchant\Models\Merchant;

class MerchantRateCard extends Model
{
    protected $guarded = [];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function rateCard()
    {
        return $this->belongsTo(RateCard::class);
    }
}
