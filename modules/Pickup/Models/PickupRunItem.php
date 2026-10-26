<?php

namespace Modules\Pickup\Models;

use Illuminate\Database\Eloquent\Model;

class PickupRunItem extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_ARRIVED = 'arrived';
    public const STATUS_PICKED_UP = 'picked_up';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REMOVED = 'removed';

    public const TERMINAL_STATUSES = ['picked_up', 'failed', 'removed'];

    protected $guarded = [];

    protected $casts = [
        'sequence' => 'integer',
        'arrived_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'failed_at' => 'datetime',
        'removed_at' => 'datetime',
    ];

    public function run()
    {
        return $this->belongsTo(PickupRun::class, 'pickup_run_id');
    }

    public function pickupRequest()
    {
        return $this->belongsTo(PickupRequest::class, 'pickup_request_id');
    }
}

