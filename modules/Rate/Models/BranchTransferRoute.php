<?php

namespace Modules\Rate\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BranchTransferRoute extends Model
{
    protected $table = 'branch_transfer_routes';

    protected $fillable = [
        'route_code',
        'name',
        'branch_transfer_lane_id',
        'service_type',
        'base_rate',
        'currency',
        'distance_km',
        'estimated_hours',
        'priority',
        'is_default',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'branch_transfer_lane_id' => 'integer',
        'base_rate'               => 'decimal:2',
        'distance_km'             => 'decimal:2',
        'estimated_hours'         => 'integer',
        'priority'                => 'integer',
        'is_default'              => 'boolean',
        'is_active'               => 'boolean',
    ];

    // Relationships
    public function lane(): BelongsTo
    {
        return $this->belongsTo(BranchTransferLane::class, 'branch_transfer_lane_id');
    }

    public function fromBranch()
    {
        return $this->lane->fromBranch ?? null;
    }

    public function toBranch()
    {
        return $this->lane->toBranch ?? null;
    }

    // Scopes
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeByService(Builder $query, string $service): Builder
    {
        return $query->where('service_type', $service);
    }

    public function scopeByLane(Builder $query, int $laneId): Builder
    {
        return $query->where('branch_transfer_lane_id', $laneId);
    }

    public function scopeStandard(Builder $query): Builder
    {
        return $query->where('service_type', 'standard');
    }

    public function scopeExpress(Builder $query): Builder
    {
        return $query->where('service_type', 'express');
    }

    public function scopeSameDay(Builder $query): Builder
    {
        return $query->where('service_type', 'same_day');
    }

    public function scopeFlight(Builder $query): Builder
    {
        return $query->where('service_type', 'flight');
    }

    // Methods
    public function getAverageSpeed(): float
    {
        return $this->estimated_hours > 0
            ? $this->distance_km / $this->estimated_hours
            : 0;
    }

    public function isExpress(): bool
    {
        return $this->service_type === 'express';
    }

    public function isSameDay(): bool
    {
        return $this->service_type === 'same_day';
    }

    public function isFlight(): bool
    {
        return $this->service_type === 'flight';
    }

    public function isQuick(): bool
    {
        return $this->estimated_hours <= 3;
    }

    public function isMedium(): bool
    {
        return $this->estimated_hours > 3 && $this->estimated_hours <= 8;
    }

    public function isLong(): bool
    {
        return $this->estimated_hours > 8;
    }
}


