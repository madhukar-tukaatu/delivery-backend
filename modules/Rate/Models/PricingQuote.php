<?php

namespace Modules\Rate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Branch\Models\Branch;

class PricingQuote extends Model
{
    protected $fillable = [
        'quote_uuid',
        'pricing_setting_id',
        'branch_route_rate_id',
        'origin_branch_id',
        'destination_branch_id',
        'weight_kg',
        'distance_km',
        'packet_count',
        'is_fragile',
        'is_same_day',
        'is_branch_transfer',
        'base_rate',
        'excess_weight_kg',
        'weight_rate',
        'weight_charge',
        'excess_distance_km',
        'distance_rate',
        'distance_charge',
        'fragile_multiplier',
        'fragile_charge',
        'same_day_multiplier',
        'same_day_charge',
        'pickup_charge',
        'subtotal',
        'vat_percentage',
        'vat_amount',
        'total_amount',
        'currency',
        'pricing_snapshot',
        'expires_at',
        'quoteable_type',
        'quoteable_id',
        'created_by',
    ];

    protected $casts = [
        'weight_kg' => 'decimal:2',
        'distance_km' => 'decimal:2',
        'packet_count' => 'integer',
        'is_fragile' => 'boolean',
        'is_same_day' => 'boolean',
        'is_branch_transfer' => 'boolean',
        'base_rate' => 'decimal:2',
        'excess_weight_kg' => 'decimal:2',
        'weight_rate' => 'decimal:2',
        'weight_charge' => 'decimal:2',
        'excess_distance_km' => 'decimal:2',
        'distance_rate' => 'decimal:2',
        'distance_charge' => 'decimal:2',
        'fragile_multiplier' => 'decimal:4',
        'fragile_charge' => 'decimal:2',
        'same_day_multiplier' => 'decimal:4',
        'same_day_charge' => 'decimal:2',
        'pickup_charge' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'vat_percentage' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'pricing_snapshot' => 'array',
        'expires_at' => 'datetime',
    ];

    public function setting()
    {
        return $this->belongsTo(
            PricingSetting::class,
            'pricing_setting_id'
        );
    }

    public function routeRate()
    {
        return $this->belongsTo(
            BranchRouteRate::class,
            'branch_route_rate_id'
        );
    }

    public function originBranch()
    {
        return $this->belongsTo(
            Branch::class,
            'origin_branch_id'
        );
    }

    public function destinationBranch()
    {
        return $this->belongsTo(
            Branch::class,
            'destination_branch_id'
        );
    }

    public function quoteable(): MorphTo
    {
        return $this->morphTo();
    }
}