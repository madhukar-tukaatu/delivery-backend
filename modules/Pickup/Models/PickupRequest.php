<?php

declare(strict_types=1);

namespace Modules\Pickup\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Branch\Models\Branch;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantPickupLocation;
use Modules\Pickup\Support\PickupStatus;

final class PickupRequest extends Model
{
    protected $table = 'pickup_requests';

    protected $fillable = [
        'request_number',

        'merchant_id',

        'store_reference',

        'branch_id',
        'sub_branch_id',

        'pickup_branch_id',
        'pickup_sub_branch_id',

        'assigned_to',
        'picked_up_by',

        'pickup_name',
        'pickup_phone',
        'pickup_email',

        'pickup_address',
        'pickup_city',
        'pickup_area',

        'pickup_lat',
        'pickup_lng',

        'preferred_pickup_at',

        'parcel_quantity',

        'status',

        'remarks',

        'requested_at',
        'assigned_at',
        'picked_up_at',
        'failed_at',
        'failed_reason',

        'pickup_location_id',

        'accepted_at',
        'received_at_origin_at',

        'assigned_by',
        'arrived_at',
        'completed_at',
        'cancelled_at',
    ];

    protected $casts = [
        'pickup_lat' => 'float',
        'pickup_lng' => 'float',

        'preferred_pickup_at' => 'datetime',

        'requested_at' => 'datetime',
        'assigned_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'failed_at' => 'datetime',

        'accepted_at' => 'datetime',
        'received_at_origin_at' => 'datetime',

        'arrived_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',

        'parcel_quantity' => 'integer',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(
            Merchant::class,
            'merchant_id'
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

    public function pickupBranch(): BelongsTo
    {
        return $this->belongsTo(
            Branch::class,
            'pickup_branch_id'
        );
    }

    public function pickupSubBranch(): BelongsTo
    {
        return $this->belongsTo(
            Branch::class,
            'pickup_sub_branch_id'
        );
    }

    public function pickupLocation(): BelongsTo
    {
        return $this->belongsTo(
            MerchantPickupLocation::class,
            'pickup_location_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Assigned Staff / Rider
    |--------------------------------------------------------------------------
    */

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(
            \App\Models\User::class,
            'assigned_to'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Compatibility alias
    |--------------------------------------------------------------------------
    |
    | Existing controller/frontend code uses assignedRider.
    | Keep this alias so both names work.
    |
    */

    public function assignedRider(): BelongsTo
    {
        return $this->belongsTo(
            \App\Models\User::class,
            'assigned_to'
        );
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(
            \App\Models\User::class,
            'assigned_by'
        );
    }

    public function pickedUpBy(): BelongsTo
    {
        return $this->belongsTo(
            \App\Models\User::class,
            'picked_up_by'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Pickup Shipments
    |--------------------------------------------------------------------------
    */

    public function shipments(): HasMany
    {
        return $this->hasMany(
            PickupRequestShipment::class,
            'pickup_request_id'
        );
    }

    public function activeShipments(): HasMany
    {
        return $this->hasMany(
            PickupRequestShipment::class,
            'pickup_request_id'
        )->whereNull('removed_at');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeActive(
        Builder $query
    ): Builder {
        return $query->whereIn(
            'status',
            PickupStatus::active()
        );
    }

    public function scopeAcceptingShipments(
        Builder $query
    ): Builder {
        return $query->whereIn(
            'status',
            PickupStatus::acceptingShipments()
        );
    }

    public function scopeForMerchant(
        Builder $query,
        int $merchantId
    ): Builder {
        return $query->where(
            'merchant_id',
            $merchantId
        );
    }

    public function scopeForBranch(
        Builder $query,
        int $branchId
    ): Builder {
        return $query->where(function (Builder $q) use ($branchId): void {

            $q->where(
                'branch_id',
                $branchId
            )
                ->orWhere(
                    'sub_branch_id',
                    $branchId
                )
                ->orWhere(
                    'pickup_branch_id',
                    $branchId
                )
                ->orWhere(
                    'pickup_sub_branch_id',
                    $branchId
                );
        });
    }
}