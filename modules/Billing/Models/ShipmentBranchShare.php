<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Branch\Models\Branch;
use Modules\Shipment\Models\Shipment;

class ShipmentBranchShare extends Model
{
    protected $guarded = [];

    protected $casts = [
        'percent' => 'float',
        'fare_amount' => 'float',
        'share_amount' => 'float',
        'transport_amount' => 'float',
        'extra_distance_amount' => 'float',
        'allocation_amount' => 'float',
        'hq_rate' => 'float',
        'hq_commission_amount' => 'float',
        'net_amount' => 'float',
        'flags' => 'array',
        'computed_at' => 'datetime',
    ];

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function collectingBranch()
    {
        return $this->belongsTo(Branch::class, 'collecting_branch_id');
    }

    public function statement()
    {
        return $this->belongsTo(InterBranchStatement::class, 'statement_id');
    }
}
