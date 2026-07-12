<?php

namespace Modules\Branch\Models;

use Illuminate\Database\Eloquent\Model;

class BranchPricingRule extends Model
{
    protected $fillable = [
        'branch_id',
        'base_radius_km',
        'base_pickup_fee',
        'base_delivery_fee',
        'pickup_extra_per_km',
        'delivery_extra_per_km',
        'max_pickup_distance_km',
        'max_delivery_distance_km',
        'base_weight_kg',
        'extra_weight_per_kg',
        'cod_fee_fixed',
        'cod_fee_percentage',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}