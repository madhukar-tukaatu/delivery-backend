<?php

namespace Modules\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Shipment\Models\Shipment;

class DispatchManifestItem extends Model
{
    protected $guarded = [];

    public function manifest()
    {
        return $this->belongsTo(DispatchManifest::class, 'dispatch_manifest_id');
    }

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }
}
