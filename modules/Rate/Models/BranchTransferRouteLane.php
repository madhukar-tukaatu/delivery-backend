<?php

declare(strict_types=1);

namespace Modules\Rate\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ordered lane within a transfer route.
 *
 * A route is a PATH composed of one or more physical lanes chained in order via
 * sequence_number. Example: route KTM -> Itahari (transit Bardibas) is made of
 *   sequence 1: lane KTM -> Bardibas
 *   sequence 2: lane Bardibas -> Itahari
 *
 * This is the join between branch_transfer_routes and branch_transfer_lanes.
 */
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
        'branch_transfer_lane_id'  => 'integer',
        'sequence_number'          => 'integer',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(BranchTransferRoute::class, 'branch_transfer_route_id');
    }

    public function lane(): BelongsTo
    {
        return $this->belongsTo(BranchTransferLane::class, 'branch_transfer_lane_id');
    }
}
