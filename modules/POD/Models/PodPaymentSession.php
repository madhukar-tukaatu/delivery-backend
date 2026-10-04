<?php

namespace Modules\POD\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use Modules\Delivery\Models\DeliveryAssignment;
use Modules\Merchant\Models\Merchant;
use Modules\Shipment\Models\Shipment;

class PodPaymentSession extends Model
{
    protected $table = 'pod_payment_sessions';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'failed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'last_polled_at' => 'datetime',
        'response_payload' => 'array',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function deliveryAssignment(): BelongsTo
    {
        return $this->belongsTo(DeliveryAssignment::class, 'delivery_assignment_id');
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isReady(): bool
    {
        return $this->status === 'ready'
            || (in_array($this->status, ['pending', 'ready'], true) && filled($this->qr_payload));
    }
}
