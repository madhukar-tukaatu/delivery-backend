<?php

namespace Modules\Rate\Models;

use Illuminate\Database\Eloquent\Model;

class RateCard extends Model
{
    protected $guarded = [];

    public function rules()
    {
        return $this->hasMany(RateRule::class);
    }
}
