<?php

declare(strict_types=1);

namespace Modules\Pickup\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Merchant\Models\Merchant;
use Modules\Shipment\Models\Shipment;

final class PickupCallbackLog extends Model
{
    protected $table = 'pickup_callback_logs';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'attempt_count' => 'integer',
        'response_status_code' => 'integer',
        'last_attempt_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';

    public function pickupRequest(): BelongsTo
    {
        return $this->belongsTo(
            PickupRequest::class,
            'pickup_request_id'
        );
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(
            Merchant::class,
            'merchant_id'
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
