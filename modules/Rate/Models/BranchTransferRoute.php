<?php

namespace Modules\Rate\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BranchTransferRoute extends Model
{
    protected $table = 'branch_transfer_routes';

    protected $fillable = [
        'route_code',
        'name',
        'branch_transfer_lane_id',
        'transit_branch_ids',
        'checkpoints',
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
        'transit_branch_ids'      => 'array',
        'checkpoints'             => 'array',
        'base_rate'               => 'decimal:2',
        'distance_km'             => 'decimal:2',
        'estimated_hours'         => 'integer',
        'priority'                => 'integer',
        'is_default'              => 'boolean',
        'is_active'               => 'boolean',
    ];

    // Relationships

    /**
     * Backward-compat anchor lane (first lane of the path). Nullable now that a
     * route is composed of an ordered list of lanes.
     */
    public function lane(): BelongsTo
    {
        return $this->belongsTo(BranchTransferLane::class, 'branch_transfer_lane_id');
    }

    /**
     * Ordered lane segments that make up this route (KTM->Bardibas, Bardibas->Itahari).
     */
    public function routeLanes(): HasMany
    {
        return $this->hasMany(BranchTransferRouteLane::class, 'branch_transfer_route_id')
            ->orderBy('sequence_number');
    }

    /**
     * The physical lanes of this route, in traversal order.
     *
     * @return Collection<int, BranchTransferLane>
     */
    public function orderedLanes(): Collection
    {
        $this->loadMissing('routeLanes.lane.fromBranch', 'routeLanes.lane.toBranch');

        $lanes = $this->routeLanes
            ->map(fn (BranchTransferRouteLane $rl) => $rl->lane)
            ->filter()
            ->values();

        // Backward compatibility: legacy single-lane routes with no join rows.
        if ($lanes->isEmpty() && $this->lane) {
            return (new Collection([$this->lane]));
        }

        return $lanes;
    }

    public function originBranch()
    {
        return $this->orderedLanes()->first()?->fromBranch;
    }

    public function destinationBranch()
    {
        return $this->orderedLanes()->last()?->toBranch;
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

    // Transit helpers

    /**
     * Number of intermediate hubs (transits) on the path.
     * Derived from the ordered lanes: N lanes => N-1 transits.
     * Falls back to the transit_branch_ids metadata for legacy rows.
     */
    public function getTransitCount(): int
    {
        $laneCount = $this->orderedLanes()->count();
        if ($laneCount > 0) {
            return max(0, $laneCount - 1);
        }

        return is_array($this->transit_branch_ids) ? count($this->transit_branch_ids) : 0;
    }

    public function hasTransits(): bool
    {
        return $this->getTransitCount() > 0;
    }

    /**
     * Ordered transit (intermediate) branch IDs derived from the lane chain:
     * the "to" of every lane except the last is a transit hub.
     * Falls back to transit_branch_ids metadata for legacy single-lane rows.
     *
     * @return int[]
     */
    public function getTransitBranchIds(): array
    {
        $lanes = $this->orderedLanes();

        if ($lanes->count() > 1) {
            return $lanes
                ->slice(0, -1)
                ->map(fn ($lane) => (int) $lane->to_branch_id)
                ->values()
                ->all();
        }

        return array_map('intval', is_array($this->transit_branch_ids) ? $this->transit_branch_ids : []);
    }

    /**
     * Full ordered path of branch IDs: origin -> transits -> destination.
     * Built by walking the ordered lanes.
     *
     * @return int[]
     */
    public function getPathBranchIds(): array
    {
        $lanes = $this->orderedLanes();

        if ($lanes->isNotEmpty()) {
            $path = [(int) $lanes->first()->from_branch_id];
            foreach ($lanes as $lane) {
                $path[] = (int) $lane->to_branch_id;
            }

            return $path;
        }

        // Legacy fallback: single anchor lane + transit metadata.
        $lane = $this->lane;
        if (!$lane) {
            return [];
        }

        $transits = is_array($this->transit_branch_ids) ? $this->transit_branch_ids : [];

        return array_map('intval', [
            $lane->from_branch_id,
            ...$transits,
            $lane->to_branch_id,
        ]);
    }

    /**
     * Total distance across all lane segments (km). Falls back to the route's own
     * stored distance_km for legacy rows.
     */
    public function getTotalDistanceKm(): float
    {
        $lanes = $this->orderedLanes();

        if ($lanes->isNotEmpty()) {
            return (float) $lanes->sum(fn ($lane) => (float) $lane->distance_km);
        }

        return (float) ($this->distance_km ?? 0);
    }

    /**
     * Total estimated hours across all lane segments. Falls back to the route's
     * own stored estimated_hours for legacy rows.
     */
    public function getTotalEstimatedHours(): float
    {
        $lanes = $this->orderedLanes();

        if ($lanes->isNotEmpty()) {
            return (float) $lanes->sum(fn ($lane) => (float) $lane->estimated_hours);
        }

        return (float) ($this->estimated_hours ?? 0);
    }

    /**
     * Normalized road checkpoints (map-picked waypoints; NOT branches).
     *
     * @return array<int, array{name:?string, city:?string, landmark:?string, latitude:?float, longitude:?float}>
     */
    public function getCheckpoints(): array
    {
        $raw = is_array($this->checkpoints) ? $this->checkpoints : [];

        $normalized = [];
        foreach ($raw as $cp) {
            if (!is_array($cp)) {
                continue;
            }

            $normalized[] = [
                'name'      => isset($cp['name']) ? (string) $cp['name'] : null,
                'city'      => isset($cp['city']) ? (string) $cp['city'] : null,
                'landmark'  => isset($cp['landmark']) ? (string) $cp['landmark'] : null,
                'latitude'  => isset($cp['latitude']) ? (float) $cp['latitude'] : null,
                'longitude' => isset($cp['longitude']) ? (float) $cp['longitude'] : null,
            ];
        }

        return $normalized;
    }
}


