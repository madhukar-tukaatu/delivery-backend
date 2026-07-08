<?php

namespace Modules\Routing\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Branch\Models\Branch;
use Modules\Shipment\Models\Shipment;

class ShipmentRouteStep extends Model
{
    protected $guarded = [];

    protected $casts = [
        'distance_km' => 'decimal:2',
        'fee' => 'decimal:2',
        'estimated_hours' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }

    public function fromBranch()
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch()
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }
}
