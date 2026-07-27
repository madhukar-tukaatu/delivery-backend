<?php

namespace Modules\Rate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BranchTransferLane extends Model
{
    protected $table = 'branch_transfer_lanes';

    protected $fillable = [
        'from_branch_id',
        'to_branch_id',
        'service_type',
        'transport_mode',
        'distance_km',
        'estimated_hours',
        'priority',
        'is_bidirectional',
        'is_active',
    ];

    protected $casts = [
        'from_branch_id' => 'integer',
        'to_branch_id' => 'integer',
        'distance_km' => 'decimal:2',
        'estimated_hours' => 'integer',
        'priority' => 'integer',
        'is_bidirectional' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(
            \Modules\Branch\Models\Branch::class,
            'from_branch_id'
        );
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(
            \Modules\Branch\Models\Branch::class,
            'to_branch_id'
        );
    }

    public function routeMappings(): HasMany
    {
        return $this->hasMany(
            BranchTransferRouteLane::class,
            'branch_transfer_lane_id'
        );
    }
}
