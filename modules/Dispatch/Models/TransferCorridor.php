<?php

declare(strict_types=1);

namespace Modules\Dispatch\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransferCorridor extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'avg_speed_kmh' => 'integer',
        'dwell_minutes' => 'integer',
        'priority' => 'integer',
    ];

    public function stops(): HasMany
    {
        return $this->hasMany(TransferCorridorStop::class)->orderBy('sequence');
    }
}
