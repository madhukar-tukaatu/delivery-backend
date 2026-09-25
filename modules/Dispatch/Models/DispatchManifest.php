<?php

namespace Modules\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;

class DispatchManifest extends Model
{
    protected $guarded = [];

    protected $casts = [
        'dispatched_at' => 'datetime',
        'received_at' => 'datetime',
        'is_multi_hop' => 'boolean',
        'transit_branch_ids' => 'array',
    ];

    public function items()
    {
        return $this->hasMany(DispatchManifestItem::class);
    }
}
