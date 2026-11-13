<?php

declare(strict_types=1);

namespace Modules\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\Branch;
use Modules\Shipment\Models\Shipment;

class TransferRunItem extends Model
{
    protected $guarded = [];

    public function run(): BelongsTo
    {
        return $this->belongsTo(TransferRun::class, 'transfer_run_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function dropStopBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'drop_stop_branch_id');
    }
}
