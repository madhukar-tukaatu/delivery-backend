<?php

declare(strict_types=1);

namespace Modules\Rate\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\Branch;
use Modules\Rate\Models\BranchTransferRoute;

class BranchRouteRate extends Model
{
    protected $table = 'branch_route_rates';

    protected $fillable = [
        'pickup_branch_id',
        'delivery_branch_id',
        'branch_transfer_route_id',
        'base_rate',
        'is_active',
        'express_enabled',
        'same_day_enabled',
    ];

    protected $casts = [
        'pickup_branch_id'          => 'integer',
        'delivery_branch_id'        => 'integer',
        'branch_transfer_route_id'  => 'integer',
        'base_rate'                 => 'decimal:2',
        'is_active'                 => 'boolean',
        'express_enabled'           => 'boolean',
        'same_day_enabled'          => 'boolean',
    ];

    public function transferRoute(): BelongsTo
    {
        return $this->belongsTo(
            BranchTransferRoute::class,
            'branch_transfer_route_id'
        );
    }

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