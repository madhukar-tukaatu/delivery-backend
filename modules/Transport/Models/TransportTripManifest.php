<?php

namespace Modules\Transport\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Dispatch\Models\DispatchManifest;

class TransportTripManifest extends Model
{
    protected $table = 'transport_trip_manifests';

    protected $guarded = [];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(TransportTrip::class, 'transport_trip_id');
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(DispatchManifest::class, 'dispatch_manifest_id');
    }
}

