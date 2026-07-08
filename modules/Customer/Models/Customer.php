<?php

namespace Modules\Customer\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Merchant\Models\Merchant;

class Customer extends Model
{
    protected $guarded = [];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }
}
