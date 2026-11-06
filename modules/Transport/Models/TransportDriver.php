<?php

namespace Modules\Transport\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Branch\Models\Branch;

class TransportDriver extends Model
{
    protected $table = 'transport_drivers';

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_ON_TRIP = 'on_trip';
    public const STATUS_OFF_DUTY = 'off_duty';
    public const STATUS_INACTIVE = 'inactive';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(TransportVehicle::class, 'assigned_vehicle_id');
    }

    public function baseBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'base_branch_id');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(TransportTrip::class, 'driver_id');
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

