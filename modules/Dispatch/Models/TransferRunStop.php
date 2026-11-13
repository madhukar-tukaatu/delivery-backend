<?php

declare(strict_types=1);

namespace Modules\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\Branch;

class TransferRunStop extends Model
{
    protected $guarded = [];

    protected $casts = [
        'sequence' => 'integer',
        'planned_arrival_at' => 'datetime',
        'arrived_at' => 'datetime',
        'departed_at' => 'datetime',
        'expected_parcels' => 'integer',
        'received_parcels' => 'integer',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(TransferRun::class, 'transfer_run_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(DispatchManifest::class, 'dispatch_manifest_id');
    }
}
