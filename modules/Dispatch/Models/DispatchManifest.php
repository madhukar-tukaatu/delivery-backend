<?php

namespace Modules\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;

class DispatchManifest extends Model
{
    protected $guarded = [];

    protected $casts = [
        'dispatched_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(DispatchManifestItem::class);
    }
}
