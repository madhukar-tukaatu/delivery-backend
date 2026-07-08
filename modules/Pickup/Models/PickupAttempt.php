<?php

namespace Modules\Pickup\Models;

use Illuminate\Database\Eloquent\Model;

class PickupAttempt extends Model
{
    protected $guarded = [];

    public function pickupRequest()
    {
        return $this->belongsTo(PickupRequest::class);
    }
}
