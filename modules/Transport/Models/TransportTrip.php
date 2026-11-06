<?php

namespace Modules\Transport\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Branch\Models\Branch;
use Modules\Dispatch\Models\DispatchManifest;
use Modules\Rate\Models\BranchTransferRoute;

class TransportTrip extends Model
{
    protected $table = 'transport_trips';

    protected $guarded = [];

    protected $casts = [
        'total_weight_kg' => 'float',
        'total_manifests_count' => 'integer',
        'total_parcels_count' => 'integer',
        'freight_cost' => 'float',
        'scheduled_departure_at' => 'datetime',
        'departed_at' => 'datetime',
        'arrived_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_LOADING = 'loading';
    public const STATUS_DISPATCHED = 'dispatched';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_ARRIVED = 'arrived';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(BranchTransferRoute::class, 'route_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(TransportVehicle::class, 'vehicle_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(TransportDriver::class, 'driver_id');
    }

    public function tripManifests(): HasMany
    {
        return $this->hasMany(TransportTripManifest::class, 'transport_trip_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_SCHEDULED,
            self::STATUS_LOADING,
            self::STATUS_DISPATCHED,
            self::STATUS_IN_TRANSIT,
            self::STATUS_ARRIVED,
        ]);
    }
}

