<?php

namespace Modules\Rate\Models;

use Illuminate\Database\Eloquent\Model;

final class PricingReturnRule extends Model
{
    protected $fillable = [
        'scenario_code',
        'name',
        'base_rate_percentage',
        'distance_rate_per_km',
        'fixed_charge',
        'is_active',
        'updated_by',
    ];

    protected $casts = [
        'base_rate_percentage' => 'decimal:2',
        'distance_rate_per_km' => 'decimal:2',
        'fixed_charge' => 'decimal:2',
        'is_active' => 'boolean',
        'updated_by' => 'integer',
    ];
}
