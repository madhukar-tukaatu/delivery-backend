<?php

namespace Modules\Rate\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Branch\Models\CoverageLocation;

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
        'is_active',
        'checkpoints',
        'variant_name',
    ];

    protected $casts = [
        'from_branch_id'   => 'integer',
        'to_branch_id'     => 'integer',
        'distance_km'      => 'decimal:2',
        'estimated_hours'  => 'decimal:2',
        'priority'         => 'integer',
        'is_active'        => 'boolean',
        'checkpoints'      => 'array',
    ];

    // Relationships
    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(CoverageLocation::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(CoverageLocation::class, 'to_branch_id');
    }

    public function routes(): HasMany
    {
        return $this->hasMany(BranchTransferRoute::class, 'branch_transfer_lane_id');
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

    public function scopeRoad(Builder $query): Builder
    {
        return $query->where('transport_mode', 'road');
    }

    public function scopeFlight(Builder $query): Builder
    {
        return $query->where('transport_mode', 'flight');
    }

    public function scopeFromBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('from_branch_id', $branchId);
    }

    public function scopeToBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('to_branch_id', $branchId);
    }

    public function scopeBetween(Builder $query, int $fromId, int $toId): Builder
    {
        return $query->where('from_branch_id', $fromId)
            ->where('to_branch_id', $toId);
    }

    // Methods
    public function getSpeedKmPerHour(): float
    {
        return $this->estimated_hours > 0 
            ? $this->distance_km / $this->estimated_hours 
            : 0;
    }

    /**
     * Get normalized checkpoints for this lane.
     * Checkpoints are road waypoints that describe the physical path this lane takes.
     */
    public function getCheckpoints(): array
    {
        $raw = is_array($this->checkpoints) ? $this->checkpoints : [];
        $normalized = [];

        foreach ($raw as $cp) {
            if (!is_array($cp)) {
                continue;
            }

            $name      = isset($cp['name']) ? trim((string) $cp['name']) : '';
            $latitude  = isset($cp['latitude']) ? (float) $cp['latitude'] : null;
            $longitude = isset($cp['longitude']) ? (float) $cp['longitude'] : null;

            // Skip empty checkpoints
            if ($name === '' && $latitude === null && $longitude === null) {
                continue;
            }

            $normalized[] = [
                'name'      => $name ?: null,
                'city'      => isset($cp['city']) ? (string) $cp['city'] : null,
                'landmark'  => isset($cp['landmark']) ? (string) $cp['landmark'] : null,
                'latitude'  => $latitude,
                'longitude' => $longitude,
            ];
        }

        return $normalized;
    }

    public function getDisplayName(): string
    {
        if ($this->variant_name) {
            return "{$this->variant_name}";
        }
        return "Direct";
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
