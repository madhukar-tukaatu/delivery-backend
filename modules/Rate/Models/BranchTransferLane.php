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
        'path_signature',
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
     * Normalize checkpoint input into the canonical lane-path shape.
     *
     * Legacy rows may use lat/lng or label/display_name keys. Keep those
     * readable while persisting one stable shape for validation and hashing.
     */
    public static function normalizeCheckpoints(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $checkpoint) {
            if (!is_array($checkpoint)) {
                continue;
            }

            $name = trim((string) ($checkpoint['name']
                ?? $checkpoint['label']
                ?? $checkpoint['display_name']
                ?? ''));
            $city = trim((string) ($checkpoint['city'] ?? ''));
            $landmark = trim((string) ($checkpoint['landmark'] ?? ''));

            $latitude = self::normalizeCoordinate(
                $checkpoint['latitude'] ?? $checkpoint['lat'] ?? null,
                -90,
                90,
            );
            $longitude = self::normalizeCoordinate(
                $checkpoint['longitude']
                    ?? $checkpoint['lng']
                    ?? $checkpoint['lon']
                    ?? null,
                -180,
                180,
            );

            if ($name === '' && $city === '' && $landmark === ''
                && $latitude === null && $longitude === null) {
                continue;
            }

            $normalized[] = [
                'name' => $name !== '' ? $name : null,
                'city' => $city !== '' ? $city : null,
                'landmark' => $landmark !== '' ? $landmark : null,
                'latitude' => $latitude,
                'longitude' => $longitude,
            ];
        }

        return $normalized;
    }

    /**
     * Return a deterministic identity for the ordered checkpoint path.
     * Metadata is included so a checkpoint change is treated as a path variant.
     */
    public static function checkpointSignature(array $checkpoints): string
    {
        return hash(
            'sha256',
            json_encode(
                self::normalizeCheckpoints($checkpoints),
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        );
    }

    private static function normalizeCoordinate(
        mixed $value,
        float $minimum,
        float $maximum,
    ): ?float {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        $coordinate = (float) $value;

        if ($coordinate < $minimum || $coordinate > $maximum) {
            return null;
        }

        return round($coordinate, 7);
    }

    /**
     * Get normalized checkpoints for this lane.
     * Checkpoints are road waypoints that describe the physical path this lane takes.
     */
    public function getCheckpoints(): array
    {
        return self::normalizeCheckpoints($this->checkpoints);
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

    /**
     * Check if this lane has a corresponding direct route.
     */
    public function hasDirectRoute(): bool
    {
        return $this->routes()
            ->where('service_type', $this->service_type)
            ->exists();
    }

    /**
     * Get the direct route for this lane if it exists.
     */
    public function getDirectRoute(): ?BranchTransferRoute
    {
        return $this->routes()
            ->where('service_type', $this->service_type)
            ->first();
    }
}
