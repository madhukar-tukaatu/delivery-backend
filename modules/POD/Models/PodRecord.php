<?php

namespace Modules\POD\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Shipment\Models\Shipment;

class PodRecord extends Model
{
    protected $table = 'pod_records';
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
