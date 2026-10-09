<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Shipment\Models\Shipment;

class InterBranchStatementLine extends Model
{
    protected $guarded = [];

    protected $casts = [
        'share_amount' => 'float',
        'transport_amount' => 'float',
        'extra_distance_amount' => 'float',
        'amount' => 'float',
    ];

    public function statement()
    {
        return $this->belongsTo(InterBranchStatement::class, 'statement_id');
    }

    public function share()
    {
        return $this->belongsTo(ShipmentBranchShare::class, 'shipment_branch_share_id');
    }

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }
}
