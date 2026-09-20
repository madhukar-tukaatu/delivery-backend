<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Branch\Models\Branch;
use Modules\Merchant\Models\Merchant;
use Modules\Shipment\Models\Shipment;

class BranchCommissionBill extends Model
{
    protected $guarded = [];

    protected $casts = [
        'delivery_charge_base' => 'float',
        'commission_rate' => 'float',
        'commission_amount' => 'float',
        'paid_at' => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function settlement()
    {
        return $this->belongsTo(BranchCommissionSettlement::class, 'settlement_id');
    }
}
