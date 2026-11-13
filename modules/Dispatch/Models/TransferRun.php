<?php

declare(strict_types=1);

namespace Modules\Dispatch\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Branch\Models\Branch;

class TransferRun extends Model
{
    public const STATUS_PLANNED = 'planned';
    public const STATUS_LOADING = 'loading';
    public const STATUS_DISPATCHED = 'dispatched';
    public const STATUS_AT_STOP = 'at_stop';
    public const STATUS_PARTIALLY_RECEIVED = 'partially_received';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const ACTIVE_STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_LOADING,
        self::STATUS_DISPATCHED,
        self::STATUS_AT_STOP,
        self::STATUS_PARTIALLY_RECEIVED,
    ];

    protected $guarded = [];

    protected $casts = [
        'planned_departure_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'service_types' => 'array',
        'estimated_km' => 'float',
        'estimated_hours' => 'float',
        'expected_weight_kg' => 'float',
        'max_weight_kg' => 'float',
        'transport_cost' => 'float',
        'current_stop_sequence' => 'integer',
        'expected_count' => 'integer',
        'max_parcels' => 'integer',
    ];

    public function originBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'origin_branch_id');
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rider_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stops(): HasMany
    {
        return $this->hasMany(TransferRunStop::class)->orderBy('sequence');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransferRunItem::class);
    }

    public function manifests(): HasMany
    {
        return $this->hasMany(DispatchManifest::class, 'transfer_run_id');
    }
}
