<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/** Push status of one billing webhook event sent to a marketplace (queued -> delivered | failed | skipped). */
class MarketplaceBillDelivery extends Model
{
    protected $guarded = [];

    protected $casts = [
        'attempts' => 'integer',
        'http_status' => 'integer',
        'queued_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];
}