<?php

namespace Modules\Rate\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\CoverageLocation;

final class DeliveryZone extends Model
{
    protected $table = 'delivery_zones';

    protected $fillable = [
        'branch_id',
        'zone_name',
        'zone_code',
        'area_name',
        'description',
        'latitude',
        'longitude',
        'coverage_radius_km',
        'priority',
        'is_active',
    ];

    protected $casts = [
        'branch_id'           => 'integer',
        'latitude'            => 'decimal:8',
        'longitude'           => 'decimal:8',
        'coverage_radius_km'  => 'decimal:2',
        'priority'            => 'integer',
        'is_active'           => 'boolean',
    ];

    // Relationships
    public function branch(): BelongsTo
    {
        return $this->belongsTo(CoverageLocation::class, 'branch_id');
    }

    // Scopes
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeByBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('priority');
    }
}
