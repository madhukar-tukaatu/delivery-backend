<?php

/*
|--------------------------------------------------------------------------
| PricingSetting model fields
|--------------------------------------------------------------------------
|
| Merge these fillable and cast entries into the existing PricingSetting model.
*/

protected $fillable = [
    'name',
    'included_weight_kg',
    'same_branch_excess_weight_rate',
    'transfer_branch_excess_weight_rate',
    'included_delivery_distance_km',
    'extra_distance_rate_per_km',
    'fragile_multiplier',
    'same_day_same_branch_multiplier',
    'same_day_transfer_branch_multiplier',
    'same_day_cutoff_time',
    'minimum_pickup_packet_count',
    'low_packet_pickup_charge',
    'vat_percentage',
    'vat_inclusive',
    'quote_validity_minutes',
    'effective_from',
    'effective_until',
    'change_reason',
    'is_active',
    'created_by',
    'updated_by',
];

protected $casts = [
    'included_weight_kg' => 'decimal:3',
    'same_branch_excess_weight_rate' => 'decimal:2',
    'transfer_branch_excess_weight_rate' => 'decimal:2',
    'included_delivery_distance_km' => 'decimal:2',
    'extra_distance_rate_per_km' => 'decimal:2',
    'fragile_multiplier' => 'decimal:4',
    'same_day_same_branch_multiplier' => 'decimal:4',
    'same_day_transfer_branch_multiplier' => 'decimal:4',
    'minimum_pickup_packet_count' => 'integer',
    'low_packet_pickup_charge' => 'decimal:2',
    'vat_percentage' => 'decimal:4',
    'vat_inclusive' => 'boolean',
    'quote_validity_minutes' => 'integer',
    'effective_from' => 'datetime',
    'effective_until' => 'datetime',
    'is_active' => 'boolean',
    'created_by' => 'integer',
    'updated_by' => 'integer',
];
