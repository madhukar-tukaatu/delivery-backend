<?php

declare(strict_types=1);

namespace Modules\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\Branch;

class TransferCorridorStop extends Model
{
    protected $guarded = [];

    protected $casts = [
        'sequence' => 'integer',
        'distance_from_prev_km' => 'float',
        'hours_from_prev' => 'float',
    ];

    public function corridor(): BelongsTo
    {
        return $this->belongsTo(TransferCorridor::class, 'transfer_corridor_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_code', 'code');
    }
}
