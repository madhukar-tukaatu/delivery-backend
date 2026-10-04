<?php

namespace Modules\POD\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Merchant\Models\Merchant;
use Modules\Shipment\Models\Shipment;

class PodRecord extends Model
{
    protected $table = 'pod_records';
    protected $guarded = [];

    protected $casts = [
        'collected_at' => 'datetime',
        'deposited_at' => 'datetime',
        'settled_at' => 'datetime',
        'payment_paid_at' => 'datetime',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }
}
