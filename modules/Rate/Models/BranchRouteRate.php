<?php

namespace Modules\Rate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Modules\Branch\Models\Branch;

class BranchRouteRate extends Model
{
    protected $fillable = [
        'origin_branch_id',
        'destination_branch_id',
        'base_rate',
        'included_weight_kg',
        'included_distance_km',
        'extra_weight_rate',
        'extra_distance_rate',
        'same_day_multiplier',
        'effective_from',
        'effective_to',
        'bidirectional',
        'is_active',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'base_rate' => 'decimal:2',
        'included_weight_kg' => 'decimal:2',
        'included_distance_km' => 'decimal:2',
        'extra_weight_rate' => 'decimal:2',
        'extra_distance_rate' => 'decimal:2',
        'same_day_multiplier' => 'decimal:4',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'bidirectional' => 'boolean',
        'is_active' => 'boolean',
    ];

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

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(function (Builder $query) {
                $query
                    ->whereNull('effective_from')
                    ->orWhereDate(
                        'effective_from',
                        '<=',
                        now()->toDateString()
                    );
            })
            ->where(function (Builder $query) {
                $query
                    ->whereNull('effective_to')
                    ->orWhereDate(
                        'effective_to',
                        '>=',
                        now()->toDateString()
                    );
            });
    }
}