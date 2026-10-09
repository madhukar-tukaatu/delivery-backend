<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Branch\Models\Branch;

class InterBranchStatement extends Model
{
    protected $guarded = [];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'total_amount' => 'float',
        'issued_at' => 'datetime',
        'paid_at' => 'datetime',
        'received_at' => 'datetime',
        'emailed_at' => 'datetime',
    ];

    public function fromBranch()
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch()
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function lines()
    {
        return $this->hasMany(InterBranchStatementLine::class, 'statement_id');
    }
}
