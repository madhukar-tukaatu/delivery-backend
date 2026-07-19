<?php

namespace Modules\Shipment\Models;

use Illuminate\Database\Eloquent\Model;

class ShipmentPriceBreakdown extends Model
{
    protected $fillable = [
        'shipment_id',
        'pricing_quote_id',
        'base_pickup_fee',
        'base_delivery_fee',
        'base_transfer_fee',
        'pickup_distance_km',
        'pickup_extra_km',
        'pickup_extra_charge',
        'delivery_distance_km',
        'delivery_extra_km',
        'delivery_extra_charge',
        'weight_charge',
        'pod_fee',
        'discount',
        'final_price',
        'snapshot_json',
    ];

    protected $casts = [
        'snapshot_json' => 'array',
    ];
}