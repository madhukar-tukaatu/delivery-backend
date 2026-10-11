<?php

namespace Modules\Delivery\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Modules\Branch\Models\Branch;

/**
 * DR delivery run (DR-KATHM-000001): one rider trip of last-mile parcels
 * from a branch. Items carry the per-parcel outcome; counts and COD totals
 * are rolled up on the run.
 */
class DeliveryRun extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_OUT = 'out_for_delivery';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    /** Runs that still hold their parcels (a parcel may be in only one). */
    public const ACTIVE_STATUSES = ['open', 'assigned', 'out_for_delivery', 'completed'];

    /** Runs whose parcel list may still change. */
    public const EDITABLE_STATUSES = ['open', 'assigned'];

    public const VEHICLE_TYPES = ['bike', 'scooter', 'cycle', 'van', 'pickup', 'on_foot', 'other'];

    protected $guarded = [];

    protected $casts = [
        'expected_count' => 'integer',
        'delivered_count' => 'integer',
        'failed_count' => 'integer',
        'returned_count' => 'integer',
        'reattempt_count' => 'integer',
        'cod_expected' => 'float',
        'cod_collected' => 'float',
        'online_collected' => 'float',
        'cash_received' => 'float',
        'cash_variance' => 'float',
        'cash_deposited' => 'boolean',
        'assigned_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'completed_at' => 'datetime',
        'closed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(DeliveryRunItem::class)->orderBy('sequence')->orderBy('id');
    }

    public function activeItems()
    {
        return $this->hasMany(DeliveryRunItem::class)->where('status', '!=', DeliveryRunItem::STATUS_REMOVED)
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
