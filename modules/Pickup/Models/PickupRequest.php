<?php

namespace Modules\Pickup\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use Modules\Merchant\Models\Merchant;
use Modules\Shipment\Models\Shipment;

class PickupRequest extends Model
{
    protected $guarded = [];

    protected $casts = [
        'preferred_pickup_at' => 'datetime',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }

    public function assignedStaff()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function attempts()
    {
        return $this->hasMany(PickupAttempt::class);
    }
}
