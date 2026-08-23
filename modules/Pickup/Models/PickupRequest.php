<?php

namespace Modules\Pickup\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Branch\Models\Branch;
use Modules\Merchant\Models\Merchant;
use Modules\Shipment\Models\Shipment;

class PickupRequest extends Model
{
    protected $guarded = [];

    protected $casts = [
        'preferred_pickup_at' => 'datetime',
        'requested_at' => 'datetime',
        'assigned_at' => 'datetime',
        'rider_arrived_at' => 'datetime',
        'collection_started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(
            Merchant::class
        );
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(
            Branch::class,
            'branch_id'
        );
    }

    public function subBranch(): BelongsTo
    {
        return $this->belongsTo(
            Branch::class,
            'sub_branch_id'
        );
    }

    public function shipments(): BelongsToMany
    {
        return $this->belongsToMany(
            Shipment::class,
            'pickup_request_shipments'
        )
            ->withPivot([
                'added_at',
                'added_by',
                'removed_at',
                'removed_by',
                'collection_status',
                'collected_at',
                'collected_by',
                'remarks',
            ])
            ->withTimestamps();
    }

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'assigned_to'
        );
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(
            PickupAttempt::class
        );
    }
}