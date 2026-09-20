<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Branch\Models\Branch;

class BranchCommissionSettlement extends Model
{
    protected $guarded = [];

    protected $casts = [
        'total_commission' => 'float',
        'adjustments' => 'float',
        'final_payable_amount' => 'float',
        'paid_at' => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function bills()
    {
        return $this->hasMany(BranchCommissionBill::class, 'settlement_id');
    }
}
