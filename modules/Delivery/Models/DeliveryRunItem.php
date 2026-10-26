<?php

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Shipment\Models\Shipment;

class DeliveryRunItem extends Model
{
    public const STATUS_QUEUED = 'queued';
    /** Rider picked it up at the warehouse and bagged it; run not started yet. */
    public const STATUS_BAGGED = 'bagged';
    public const STATUS_OUT = 'out_for_delivery';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REMOVED = 'removed';

    /** Item states that are finished on the road (no more rider action). */
    public const TERMINAL_STATUSES = ['delivered', 'failed', 'removed'];

    protected $guarded = [];

    protected $casts = [
        'sequence' => 'integer',
        'attempt' => 'integer',
        'cod_amount' => 'float',
        'collected_amount' => 'float',
        'arrived_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'removed_at' => 'datetime',
        'bagged_at' => 'datetime',
    ];

    public function run()
    {
        return $this->belongsTo(DeliveryRun::class, 'delivery_run_id');
    }

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }

    public function delivery()
    {
        return $this->belongsTo(DeliveryAssignment::class, 'delivery_id');
    }
}
