<?php

namespace Modules\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Transfer container (TR-000001): one trip from a branch to its next hop.
 * Older rows only carry manifest_number (MF-...).
 */
class DispatchManifest extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_DISPATCHED = 'dispatched';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_PARTIALLY_RECEIVED = 'partially_received';
    public const STATUS_CANCELLED = 'cancelled';

    public const VEHICLE_TYPES = ['bike', 'van', 'pickup', 'truck', 'bus_cargo', 'other'];

    protected $guarded = [];

    protected $casts = [
        'dispatched_at' => 'datetime',
        'received_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'is_multi_hop' => 'boolean',
        'transit_branch_ids' => 'array',
        'transport_cost' => 'float',
        'transport_cost_log' => 'array',
        'transport_cost_entered_at' => 'datetime',
        'transport_cost_updated_at' => 'datetime',
        'expected_count' => 'integer',
        'received_count' => 'integer',
        'missing_count' => 'integer',
        'extra_count' => 'integer',
        'auto_append' => 'boolean',
        'seal_checked_at' => 'datetime',
    ];

    public const SEAL_STATUSES = ['ok', 'mismatch', 'tampered'];

    protected $appends = ['display_number'];

    public function items()
    {
        return $this->hasMany(DispatchManifestItem::class);
    }

    public function fromBranch()
    {
        return $this->belongsTo(\Modules\Branch\Models\Branch::class, 'from_branch_id');
    }

    public function toBranch()
    {
        return $this->belongsTo(\Modules\Branch\Models\Branch::class, 'to_branch_id');
    }

    public function rider()
    {
        return $this->belongsTo(\App\Models\User::class, 'rider_user_id');
    }

    /** TR number when assigned, else the legacy MF number. */
    public function getDisplayNumberAttribute(): string
    {
        return (string) ($this->attributes['transfer_number'] ?? null ?: ($this->attributes['manifest_number'] ?? ('#'.$this->getKey())));
    }
}
