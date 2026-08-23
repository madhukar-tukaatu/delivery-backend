<?php

namespace Modules\Shipment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Branch\Models\Branch;
use Modules\Delivery\Models\DeliveryAssignment;
use Modules\Merchant\Models\Merchant;
use Modules\Pickup\Models\PickupRequest;
use Modules\Routing\Models\ShipmentRouteStep;
use Modules\Tracking\Models\TrackingEvent;

class Shipment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'fragile' => 'boolean',

        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',

        'delivery_charge_breakdown' => 'array',

        'pickup_lat' => 'decimal:7',
        'pickup_lng' => 'decimal:7',

        'delivery_lat' => 'decimal:7',
        'delivery_lng' => 'decimal:7',

        'route_distance_km' => 'decimal:2',
        'route_fee' => 'decimal:2',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(
            Merchant::class,
            'merchant_id'
        );
    }

    public function items(): HasMany
    {
        return $this->hasMany(
            ShipmentItem::class
        );
    }

    public function trackingEvents(): HasMany
    {
        return $this->hasMany(
            TrackingEvent::class
        )->latest();
    }

    public function originBranch(): BelongsTo
    {
        return $this->belongsTo(
            Branch::class,
            'origin_branch_id'
        );
    }

    public function originSubBranch(): BelongsTo
    {
        return $this->belongsTo(
            Branch::class,
            'origin_sub_branch_id'
        );
    }

    public function destinationBranch(): BelongsTo
    {
        return $this->belongsTo(
            Branch::class,
            'destination_branch_id'
        );
    }

    public function destinationSubBranch(): BelongsTo
    {
        return $this->belongsTo(
            Branch::class,
            'destination_sub_branch_id'
        );
    }

    public function currentBranch(): BelongsTo
    {
        return $this->belongsTo(
            Branch::class,
            'current_branch_id'
        );
    }

    public function currentSubBranch(): BelongsTo
    {
        return $this->belongsTo(
            Branch::class,
            'current_sub_branch_id'
        );
    }

    public function routeSteps(): HasMany
    {
        return $this->hasMany(
            ShipmentRouteStep::class
        )->orderBy('sequence');
    }

    /*
    |--------------------------------------------------------------------------
    | Pickup Requests
    |--------------------------------------------------------------------------
    */

    public function pickupRequests(): BelongsToMany
    {
        return $this->belongsToMany(
            PickupRequest::class,
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

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    */

    public function deliveryAssignment(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(
            DeliveryAssignment::class,
            'shipment_id'
        );
    }

    public function deliveryAssignments(): HasMany
    {
        return $this->hasMany(
            DeliveryAssignment::class,
            'shipment_id'
        );
    }
}