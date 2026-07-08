<?php

namespace Modules\Shipment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }

    public function items()
    {
        return $this->hasMany(ShipmentItem::class);
    }

    public function trackingEvents()
    {
        return $this->hasMany(TrackingEvent::class)->latest();
    }

    public function originBranch()
    {
        return $this->belongsTo(Branch::class, 'origin_branch_id');
    }

    public function originSubBranch()
    {
        return $this->belongsTo(Branch::class, 'origin_sub_branch_id');
    }

    public function destinationBranch()
    {
        return $this->belongsTo(Branch::class, 'destination_branch_id');
    }

    public function destinationSubBranch()
    {
        return $this->belongsTo(Branch::class, 'destination_sub_branch_id');
    }

    public function currentBranch()
    {
        return $this->belongsTo(Branch::class, 'current_branch_id');
    }

    public function currentSubBranch()
    {
        return $this->belongsTo(Branch::class, 'current_sub_branch_id');
    }

    public function routeSteps()
    {
        return $this->hasMany(ShipmentRouteStep::class)->orderBy('sequence');
    }

    public function pickupRequest(): HasOne
    {
        return $this->hasOne(PickupRequest::class, 'shipment_id');
    }

    public function pickupRequests(): HasMany
    {
        return $this->hasMany(PickupRequest::class, 'shipment_id');
    }

    public function deliveryAssignment(): HasOne
    {
        return $this->hasOne(DeliveryAssignment::class, 'shipment_id');
    }

    public function deliveryAssignments(): HasMany
    {
        return $this->hasMany(DeliveryAssignment::class, 'shipment_id');
    }
}
