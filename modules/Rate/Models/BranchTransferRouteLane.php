<?php

namespace Modules\Rate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class BranchTransferRouteLane extends Model
{
    protected $table = 'branch_transfer_route_lanes';

    protected $fillable = [
        'branch_transfer_route_id',
        'branch_transfer_lane_id',
        'sequence_number',
    ];

    protected $casts = [
        'branch_transfer_route_id' => 'integer',
        'branch_transfer_lane_id' => 'integer',
        'sequence_number' => 'integer',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(
            BranchTransferRoute::class,
            'branch_transfer_route_id'
        );
    }

    public function lane(): BelongsTo
    {
        return $this->belongsTo(
            BranchTransferLane::class,
            'branch_transfer_lane_id'
        );
    }
}
