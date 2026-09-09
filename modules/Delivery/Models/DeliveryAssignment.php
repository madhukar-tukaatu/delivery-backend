<?php

namespace Modules\Delivery\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Shipment\Models\Shipment;

class DeliveryAssignment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'assigned_date' => 'date',
        'assigned_at' => 'datetime',
        'accepted_at' => 'datetime',
        'out_for_delivery_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'pod_collected_amount' => 'decimal:2',
    ];

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * The rider currently responsible for the delivery.
     */
    public function rider()
    {
        return $this->belongsTo(User::class, 'rider_id');
    }

    /**
     * Legacy relation kept for backward compatibility.
     */
    public function staff()
    {
        return $this->belongsTo(User::class, 'delivery_staff_id');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function attempts()
    {
        return $this->hasMany(DeliveryAttempt::class);
    }
}
