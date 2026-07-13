<?php

namespace Modules\Rate\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceType extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'price_multiplier',
        'fixed_addon_fee',
        'estimated_min_hours',
        'estimated_max_hours',
        'pickup_cutoff_time',
        'same_day_only',
        'is_active',
    ];

    protected $casts = [
        'same_day_only' => 'boolean',
        'is_active' => 'boolean',
    ];
}