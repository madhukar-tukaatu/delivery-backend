<?php

namespace Modules\Shipment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'unit_weight' => 'decimal:2',
        'total_price' => 'decimal:2',
        'total_weight' => 'decimal:2',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(
            Shipment::class,
            'shipment_id'
        );
    }
}