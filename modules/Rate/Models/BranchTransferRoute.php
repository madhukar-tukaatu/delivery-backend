<?php

namespace Modules\Rate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BranchTransferRoute extends Model
{
    protected $table = 'branch_transfer_routes';

    protected $fillable = [
        'route_code',
        'name',
        'origin_branch_id',
        'destination_branch_id',
        'service_type',
        'transfer_count',
        'transit_count',
        'total_distance_km',
        'total_estimated_hours',
        'priority',
        'is_default',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'origin_branch_id'      => 'integer',
        'destination_branch_id' => 'integer',
        'transfer_count'        => 'integer',
        'transit_count'         => 'integer',
        'total_distance_km'     => 'decimal:2',
        'total_estimated_hours' => 'integer',
        'priority'              => 'integer',
        'is_default'            => 'boolean',
        'is_active'             => 'boolean',
    ];

    public function originBranch(): BelongsTo
    {
        return $this->belongsTo(
            \Modules\Branch\Models\Branch::class,
            'origin_branch_id'
        );
    }

    public function destinationBranch(): BelongsTo
    {
        return $this->belongsTo(
            \Modules\Branch\Models\Branch::class,
            'destination_branch_id'
        );
    }

    public function routeLanes(): HasMany
    {
        return $this->hasMany(
            BranchTransferRouteLane::class,
            'branch_transfer_route_id'
        )->orderBy('sequence_number');
    }
}
