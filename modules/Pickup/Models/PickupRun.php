<?php

namespace Modules\Pickup\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Branch\Models\Branch;

/**
 * PR pickup run (PR-KATHM-000001): one rider trip collecting parcels from merchants.
 * Items carry the sequenced pickup requests along the route.
 */
class PickupRun extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    /** Runs that still hold their pickups. */
    public const ACTIVE_STATUSES = ['open', 'assigned', 'in_progress', 'completed'];

    /** Runs whose pickup list may still change. */
    public const EDITABLE_STATUSES = ['open', 'assigned'];

    public const VEHICLE_TYPES = ['bike', 'scooter', 'cycle', 'van', 'pickup', 'on_foot', 'other'];

    protected $guarded = [];

    protected $casts = [
        'expected_count' => 'integer',
        'picked_up_count' => 'integer',
        'failed_count' => 'integer',
        'assigned_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'completed_at' => 'datetime',
        'closed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(PickupRunItem::class)->orderBy('sequence')->orderBy('id');
    }

    public function activeItems()
    {
        return $this->hasMany(PickupRunItem::class)->where('status', '!=', PickupRunItem::STATUS_REMOVED)
            ->orderBy('sequence')->orderBy('id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function rider()
    {
        return $this->belongsTo(User::class, 'rider_user_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE_STATUSES, true);
    }
}

