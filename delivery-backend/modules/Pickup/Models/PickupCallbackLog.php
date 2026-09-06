<?php

declare(strict_types=1);

namespace Modules\Pickup\Models;

use Illuminate\Database\Eloquent\Model;

class PickupCallbackLog extends Model
{
    protected $table = 'pickup_callback_logs';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'json',
        'response_body' => 'json',
        'last_attempt_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';

    public function pickupRequest()
    {
        return $this->belongsTo(PickupRequest::class);
    }

    public function shipment()
    {
        return $this->belongsTo(\Modules\Shipment\Models\Shipment::class);
    }

    public function merchant()
    {
        return $this->belongsTo(\Modules\Merchant\Models\Merchant::class);
    }
}
