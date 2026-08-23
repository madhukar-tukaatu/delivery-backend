<?php

namespace Modules\Pickup\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Shipment\Models\Shipment;

class PickupRequestShipment extends Model
{
    protected $table = 'pickup_request_shipments';

    protected $guarded = [];

    protected $casts = [
        'added_at' => 'datetime',
        'removed_at' => 'datetime',
        'collected_at' => 'datetime',
    ];

    public function pickupRequest(): BelongsTo
    {
        return $this->belongsTo(
            PickupRequest::class
        );
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(
            Shipment::class
        );
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'added_by'
        );
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'removed_by'
        );
    }

    public function collectedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'collected_by'
        );
    }
}