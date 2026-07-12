<?php

namespace Modules\Rate\Models;

use Illuminate\Database\Eloquent\Model;

class PricingQuote extends Model
{
    protected $fillable = [
        'quote_number',
        'merchant_id',
        'pickup_branch_id',
        'delivery_branch_id',
        'pickup_address',
        'pickup_latitude',
        'pickup_longitude',
        'delivery_address',
        'delivery_latitude',
        'delivery_longitude',
        'parcel_weight',
        'parcel_value',
        'payment_type',
        'cod_amount',
        'service_type',
        'final_price',
        'expires_at',
        'snapshot_json',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'snapshot_json' => 'array',
    ];
}