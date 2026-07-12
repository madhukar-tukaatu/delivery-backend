<?php

namespace Modules\Branch\Models;

use Illuminate\Database\Eloquent\Model;

class BranchTransferLane extends Model
{
    protected $fillable = [
        'from_branch_id',
        'to_branch_id',
        'base_transfer_fee',
        'per_kg_fee',
        'estimated_hours',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}