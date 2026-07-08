<?php

namespace Modules\Routing\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Branch\Models\Branch;

class DeliveryRouteSegment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'distance_km' => 'decimal:2',
        'base_fee' => 'decimal:2',
        'per_kg_fee' => 'decimal:2',
        'estimated_hours' => 'integer',
    ];

    public function fromBranch()
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch()
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }
}
