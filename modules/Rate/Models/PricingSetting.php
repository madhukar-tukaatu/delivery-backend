<?php

namespace Modules\Rate\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PricingSetting extends Model
{
    protected $fillable = [
        'name',
        'base_weight_kg',
        'max_weight_kg',
        'weight_limit_enabled',
        'base_distance_km',
        'local_extra_weight_rate',
        'transfer_extra_weight_rate',
        'extra_distance_rate',
        'fragile_multiplier',
        'local_same_day_multiplier',
        'transfer_same_day_multiplier',
        'local_express_multiplier',
        'transfer_express_multiplier',
        'same_day_cutoff_time',
        'minimum_free_pickup_packets',
        'small_pickup_charge',
        'min_delivery_charge',
        'max_delivery_charge',
        'delivery_charge_bounds_enabled',
        'vat_percentage',
        'vat_inclusive',
        'weight_rounding',
        'distance_rounding',
        'money_rounding',
        'fragile_enabled',
        'same_day_enabled',
        'express_enabled',
        'pickup_charge_enabled',
        'vat_enabled',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'base_weight_kg'               => 'decimal:2',
        'max_weight_kg'                => 'decimal:2',
        'weight_limit_enabled'         => 'boolean',
        'base_distance_km'             => 'decimal:2',
        'local_extra_weight_rate'      => 'decimal:2',
        'transfer_extra_weight_rate'   => 'decimal:2',
        'extra_distance_rate'          => 'decimal:2',
        'fragile_multiplier'           => 'decimal:4',
        'local_same_day_multiplier'    => 'decimal:4',
        'transfer_same_day_multiplier' => 'decimal:4',
        'local_express_multiplier'     => 'decimal:4',
        'transfer_express_multiplier'  => 'decimal:4',
        'minimum_free_pickup_packets'  => 'integer',
        'small_pickup_charge'          => 'decimal:2',
        'min_delivery_charge'          => 'decimal:2',
        'max_delivery_charge'          => 'decimal:2',
        'delivery_charge_bounds_enabled' => 'boolean',
        'vat_percentage'               => 'decimal:2',
        'vat_inclusive'                => 'boolean',
        'fragile_enabled'              => 'boolean',
        'same_day_enabled'             => 'boolean',
        'express_enabled'              => 'boolean',
        'pickup_charge_enabled'        => 'boolean',
        'vat_enabled'                  => 'boolean',
        'is_active'                    => 'boolean',
        'created_by'                   => 'integer',
        'updated_by'                   => 'integer',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function current(): ?self
    {
        return static::query()
            ->active()
            ->latest('id')
            ->first();
    }
}
