<?php

declare(strict_types=1);

namespace Modules\Pickup\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Shipment\Models\Shipment;

final class PickupRequestShipment extends Model
{
    protected $table =
        'pickup_request_shipments';

    protected $fillable = [
        'pickup_request_id',
        'shipment_id',
        'remarks',
        'removed_at',
    ];

    protected $casts = [
        'removed_at' => 'datetime',
    ];

    public function pickupRequest(): BelongsTo
    {
        return $this->belongsTo(
            PickupRequest::class,
            'pickup_request_id'
        );
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(
            Shipment::class,
            'shipment_id'
        );
    }
}