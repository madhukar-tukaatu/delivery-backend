<?php

namespace Modules\Tracking\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Shipment\Models\Shipment;

class TrackingEvent extends Model
{
    protected $guarded = [];

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }
}
