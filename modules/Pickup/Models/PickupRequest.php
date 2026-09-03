<?php

declare(strict_types=1);

namespace Modules\Pickup\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Branch\Models\Branch;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantPickupLocation;
use Modules\Pickup\Support\PickupStatus;
use Modules\Shipment\Models\Shipment;

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

        'pickup_location_id',

        'assigned_to',
        'assigned_by',

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
        'accepted_at',

        'arrived_at',
        'rider_arrived_at',

        'collection_started_at',

        'picked_up_at',

        'received_at_origin_at',

        'completed_at',

        'failed_at',
        'failed_reason',

        'cancelled_at',
    ];

    protected $casts = [
        'pickup_lat' => 'float',
        'pickup_lng' => 'float',

        'preferred_pickup_at' => 'datetime',

        'requested_at' => 'datetime',
        'assigned_at' => 'datetime',
        'accepted_at' => 'datetime',

        'arrived_at' => 'datetime',
        'rider_arrived_at' => 'datetime',

        'collection_started_at' => 'datetime',

        'picked_up_at' => 'datetime',

        'received_at_origin_at' => 'datetime',

        'completed_at' => 'datetime',

        'failed_at' => 'datetime',

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

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'assigned_to'
        );
    }

    public function assignedRider(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'assigned_to'
        );
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'assigned_by'
        );
    }

    public function pickedUpBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'picked_up_by'
        );
    }

    public function shipments(): BelongsToMany
    {
        return $this->belongsToMany(
            Shipment::class,
            'pickup_request_shipments',
            'pickup_request_id',
            'shipment_id'
        )
            ->withPivot([
                'added_at',
                'added_by',

                'removed_at',
                'removed_by',

                'status',

                'collection_status',
                'collected_at',
                'collected_by',

                'remarks',
            ])
            ->withTimestamps();
    }

    public function activeShipments(): BelongsToMany
    {
        return $this->shipments()
            ->wherePivotNull('removed_at')
            ->wherePivotIn(
                'status',
                \Modules\Pickup\Support\PickupShipmentStatus::active()
            );
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

    public function scopeForPickupLocation(
        Builder $query,
        int $pickupLocationId
    ): Builder {
        return $query->where(
            'pickup_location_id',
            $pickupLocationId
        );
    }

    /*
    |--------------------------------------------------------------------------
    | State helpers
    |--------------------------------------------------------------------------
    */

    public function canAcceptShipments(): bool
    {
        return PickupStatus::canAddShipments(
            (string) $this->status
        );
    }

    public function isClosed(): bool
    {
        return PickupStatus::isClosed(
            (string) $this->status
        );
    }

    public function isActive(): bool
    {
        return PickupStatus::isActive(
            (string) $this->status
        );
    }
}