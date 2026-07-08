<?php

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Shipment\Models\Shipment;

class DeliveryAttempt extends Model
{
    protected $guarded = [];

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }
}
