<?php

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use Modules\Shipment\Models\Shipment;

class DeliveryAssignment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'assigned_date' => 'date',
    ];

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }

    public function staff()
    {
        return $this->belongsTo(User::class, 'delivery_staff_id');
    }

    public function attempts()
    {
        return $this->hasMany(DeliveryAttempt::class);
    }
}
