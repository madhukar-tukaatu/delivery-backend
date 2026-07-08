<?php

namespace Modules\COD\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Shipment\Models\Shipment;

class CodRecord extends Model
{
    protected $guarded = [];

    protected $casts = [
        'collected_at' => 'datetime',
        'deposited_at' => 'datetime',
        'settled_at' => 'datetime',
    ];

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }
}
