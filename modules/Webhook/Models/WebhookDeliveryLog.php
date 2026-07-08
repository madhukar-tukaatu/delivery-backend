<?php

namespace Modules\Webhook\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Merchant\Models\Merchant;
use Modules\Shipment\Models\Shipment;

class WebhookDeliveryLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'last_attempt_at' => 'datetime',
        'next_retry_at' => 'datetime',
    ];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }
}
