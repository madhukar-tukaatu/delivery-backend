<?php

namespace Modules\Rate\Models;

use Illuminate\Database\Eloquent\Model;

class RateRule extends Model
{
    protected $guarded = [];

    public function rateCard()
    {
        return $this->belongsTo(RateCard::class);
    }
}
