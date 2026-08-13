<?php

declare(strict_types=1);

namespace Modules\Rate\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Branch\Models\Branch;

class BranchRouteRate extends Model
{
    protected $table = 'branch_route_rates';

    protected $fillable = [
        'pickup_branch_id',
        'delivery_branch_id',
        'base_rate',
        'is_active',
        'express_enabled',
        'same_day_enabled',
    ];

    protected $casts = [
        'pickup_branch_id'   => 'integer',
        'delivery_branch_id' => 'integer',
        'base_rate'          => 'decimal:2',
        'is_active'          => 'boolean',
        'express_enabled'    => 'boolean',
        'same_day_enabled'   => 'boolean',
    ];

    public function pickupBranch()
    {
        return $this->belongsTo(
            Branch::class,
            'pickup_branch_id'
        );
    }

    public function deliveryBranch()
    {
        return $this->belongsTo(
            Branch::class,
            'delivery_branch_id'
        );
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}