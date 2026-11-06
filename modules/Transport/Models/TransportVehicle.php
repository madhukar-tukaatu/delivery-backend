<?php

namespace Modules\Transport\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Branch\Models\Branch;

class TransportVehicle extends Model
{
    protected $table = 'transport_vehicles';

    protected $guarded = [];

    protected $casts = [
        'capacity_kg' => 'float',
        'capacity_cbm' => 'float',
        'max_parcels' => 'integer',
        'fixed_rate_per_trip' => 'float',
        'rate_per_km' => 'float',
        'is_active' => 'boolean',
    ];

    public const TYPE_BIKE = 'bike';
    public const TYPE_VAN = 'van';
    public const TYPE_PICKUP = 'pickup';
    public const TYPE_MINI_TRUCK = 'mini_truck_14ft';
    public const TYPE_HEAVY_TRUCK = 'heavy_truck_22ft';
    public const TYPE_BUS_CARGO = 'bus_cargo';
    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_BIKE,
        self::TYPE_VAN,
        self::TYPE_PICKUP,
        self::TYPE_MINI_TRUCK,
        self::TYPE_HEAVY_TRUCK,
        self::TYPE_BUS_CARGO,
        self::TYPE_OTHER,
    ];

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_ON_TRIP = 'on_trip';
    public const STATUS_MAINTENANCE = 'maintenance';
    public const STATUS_INACTIVE = 'inactive';

    public function currentBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'current_branch_id');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(TransportTrip::class, 'vehicle_id');
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(TransportDriver::class, 'assigned_vehicle_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_AVAILABLE)->where('is_active', true);
    }
}

