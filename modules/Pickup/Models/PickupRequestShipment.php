<?php

declare(strict_types=1);

namespace Modules\Pickup\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Pickup\Support\PickupShipmentStatus;
use Modules\Shipment\Models\Shipment;

final class PickupRequestShipment extends Model
{
    protected $table = 'pickup_request_shipments';

    protected $fillable = [
        'pickup_request_id',
        'shipment_id',

        'added_at',
        'added_by',

        'removed_at',
        'removed_by',

        'status',

        'collection_status',
        'collected_at',
        'collected_by',

        'remarks',
    ];

    protected $casts = [
        'added_at' => 'datetime',
        'removed_at' => 'datetime',
        'collected_at' => 'datetime',
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

    public function isActive(): bool
    {
        return $this->removed_at === null
            && in_array(
                (string) $this->status,
                PickupShipmentStatus::active(),
                true
            );
    }
}