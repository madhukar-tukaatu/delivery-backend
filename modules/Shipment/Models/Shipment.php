<?php

declare (strict_types = 1);

namespace Modules\Shipment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Branch\Models\Branch;
use Modules\Delivery\Models\DeliveryAssignment;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantPickupLocation;
use Modules\Pickup\Models\PickupRequest;
use Modules\Routing\Models\ShipmentRouteStep;
use Modules\Tracking\Models\TrackingEvent;
use App\Support\CourierStatus;

class Shipment extends Model
{
    protected $guarded = [];

    /**
     * Computed attributes serialized into JSON so every view (Shipments,
     * Pickups, Transfers) shows a single, consistent transfer status without
     * re-deriving the logic on the frontend.
     */
    protected $appends = [
        'is_transfer',
        'transfer_stage',
        'transfer_stage_label',
        'hop_meta',
        'route_progress',
    ];

    /**
     * True when the parcel must move between two different branch "nodes"
     * (origin node != destination node). A node is the sub-branch id when
     * present, otherwise the main branch id. This mirrors
     * ShipmentSortingService::classify().
     */
    public function getIsTransferAttribute(): bool
    {
        $originNode = $this->origin_sub_branch_id ?? $this->origin_branch_id;
        $destinationNode = $this->destination_sub_branch_id ?? $this->destination_branch_id;

        if ($originNode === null || $destinationNode === null) {
            return false;
        }

        return (int) $originNode !== (int) $destinationNode;
    }

    /**
     * A coarse, transfer-focused stage derived from the raw shipment status.
     * Only meaningful for cross-branch shipments; returns null otherwise so
     * local last-mile deliveries are never shown as transfers.
     *
     * Values: ready_to_dispatch | in_transit | received | out_for_delivery
     *         | delivered | returning | cancelled | null
     */
    public function getTransferStageAttribute(): ?string
    {
        if (! $this->is_transfer) {
            return null;
        }

        // A cross-branch parcel sorted for delivery is ambiguous: at the
        // ORIGIN it still needs to be dispatched; at the DESTINATION it has
        // already arrived and is now a local last-mile job.
        if ($this->status === CourierStatus::SORTED_FOR_DELIVERY) {
            $atDestination = $this->current_branch_id !== null
                && (int) $this->current_branch_id === (int) $this->destination_branch_id;

            return $atDestination ? 'received' : 'ready_to_dispatch';
        }

        return match ($this->status) {
            CourierStatus::SORTED_FOR_TRANSFER,
            CourierStatus::RECEIVED_AT_ORIGIN_BRANCH,
            CourierStatus::PICKED_UP
                => 'ready_to_dispatch',

            CourierStatus::IN_TRANSIT,
            CourierStatus::DISPATCHED_TO_DESTINATION_BRANCH,
            CourierStatus::RECEIVED_AT_TRANSIT_HUB
                => 'in_transit',

            CourierStatus::RECEIVED_AT_DESTINATION_BRANCH,
            CourierStatus::RECEIVED_AT_DESTINATION_SUB_BRANCH
                => 'received',

            CourierStatus::ASSIGNED_TO_RIDER,
            CourierStatus::OUT_FOR_DELIVERY
                => 'out_for_delivery',

            CourierStatus::DELIVERED
                => 'delivered',

            CourierStatus::RETURN_INITIATED
                => 'returning',

            CourierStatus::CANCELLED
                => 'cancelled',

            default => null,
        };
    }

    /**
     * Human-readable label for the transfer stage. Null for non-transfers.
     */
    public function getTransferStageLabelAttribute(): ?string
    {
        return match ($this->transfer_stage) {
            'ready_to_dispatch' => 'Ready to Dispatch',
            'in_transit'        => 'In Transit',
            'received'          => 'Received at Destination',
            'out_for_delivery'  => 'Out for Delivery',
            'delivered'         => 'Delivered',
            'returning'         => 'Returning',
            'cancelled'         => 'Cancelled',
            default             => null,
        };
    }

    /**
     * Hop-by-hop display metadata for transfer boards.
     * Prefer persisted next_hop / path columns; fall back to open manifest.
     */
    public function getHopMetaAttribute(): ?array
    {
        if (! $this->is_transfer) {
            return null;
        }

        $nextHopId = $this->attributes['next_hop_branch_id'] ?? null;
        $pathText = $this->attributes['path_text'] ?? null;
        $routeId = $this->attributes['transfer_route_id'] ?? null;
        $legIndex = $this->attributes['transfer_leg_index'] ?? null;

        if ($nextHopId === null && $this->status === CourierStatus::IN_TRANSIT) {
            try {
                $item = \Modules\Dispatch\Models\DispatchManifestItem::query()
                    ->where('shipment_id', $this->id)
                    ->whereIn('status', ['sent', 'dispatched', 'in_transit'])
                    ->with('manifest')
                    ->latest('id')
                    ->first();
                if ($item?->manifest) {
                    $nextHopId = $item->manifest->to_branch_id;
                    $routeId = $routeId ?? $item->manifest->route_id;
                }
            } catch (\Throwable $e) {
                // Schema may lack manifests on some environments.
            }
        }

        $nextHopName = null;
        if ($nextHopId) {
            $nextHopName = $this->relationLoaded('nextHopBranch')
                ? $this->nextHopBranch?->name
                : \Modules\Branch\Models\Branch::query()->whereKey($nextHopId)->value('name');
            if (!$nextHopName) {
                try {
                    $nextHopName = \Modules\Branch\Models\CoverageLocation::query()->whereKey($nextHopId)->value('name');
                } catch (\Throwable $e) {
                    $nextHopName = null;
                }
            }
        }

        $inTransitLabel = null;
        if ($this->status === CourierStatus::IN_TRANSIT && $nextHopName) {
            $inTransitLabel = "In transit to {$nextHopName}";
        } elseif ($this->status === CourierStatus::IN_TRANSIT && $this->destinationBranch) {
            $inTransitLabel = "In transit toward {$this->destinationBranch->name}";
        }

        return [
            'origin_name' => $this->originBranch?->name,
            'destination_name' => $this->destinationBranch?->name,
            'current_name' => $this->currentBranch?->name,
            'next_hop_branch_id' => $nextHopId !== null ? (int) $nextHopId : null,
            'next_hop_name' => $nextHopName,
            'transfer_route_id' => $routeId !== null ? (int) $routeId : null,
            'transfer_leg_index' => $legIndex !== null ? (int) $legIndex : null,
            'path_text' => $pathText,
            'route_code' => $this->attributes['route_code'] ?? null,
            'route_name' => $this->attributes['route_name'] ?? null,
            'service_type' => $this->service_type ?? null,
            'in_transit_label' => $inTransitLabel,
        ];
    }



    /**
     * Route hop progress for shipment detail (assigned transfer route).
     */
    public function getRouteProgressAttribute(): ?array
    {
        if (! $this->is_transfer) {
            return null;
        }

        try {
            return app(\Modules\Shipment\Services\TransferRouteProgressService::class)
                ->progressPayload($this);
        } catch (\Throwable $e) {
            return null;
        }
    }
    protected $casts = [

        /*
        |--------------------------------------------------------------------------
        | Boolean
        |--------------------------------------------------------------------------
        */

        'fragile'                   =>
        'boolean',

        'self_drop'                 =>
        'boolean',

        /*
        |--------------------------------------------------------------------------
        | Dates
        |--------------------------------------------------------------------------
        */

        'delivered_at'              =>
        'datetime',

        'cancelled_at'              =>
        'datetime',

        /*
        |--------------------------------------------------------------------------
        | JSON
        |--------------------------------------------------------------------------
        */

        'delivery_charge_breakdown' =>
        'array',

        'packet_products'           =>
        'array',

        /*
        |--------------------------------------------------------------------------
        | Coordinates
        |--------------------------------------------------------------------------
        */

        'pickup_lat'                =>
        'decimal:7',

        'pickup_lng'                =>
        'decimal:7',

        'delivery_lat'              =>
        'decimal:7',

        'delivery_lng'              =>
        'decimal:7',

        /*
        |--------------------------------------------------------------------------
        | Decimal values
        |--------------------------------------------------------------------------
        */

        'route_distance_km'         =>
        'decimal:2',

        'route_fee'                 =>
        'decimal:2',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(
            Merchant::class,
            'merchant_id'
        );
    }

    public function pickupLocation(): BelongsTo
    {
        return $this->belongsTo(
            MerchantPickupLocation::class,
            'pickup_location_id'
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

    // public function pickupRequests(): BelongsToMany
    // {
    //     return $this->belongsToMany(
    //         PickupRequest::class,
    //         'pickup_request_shipments'
    //     )
    //         ->withPivot([
    //             'added_at',
    //             'added_by',
    //             'removed_at',
    //             'removed_by',
    //             'collection_status',
    //             'collected_at',
    //             'collected_by',
    //             'remarks',
    //         ])
    //         ->withTimestamps();
    // }

    public function pickupRequests(): BelongsToMany
    {
        return $this->belongsToMany(
            PickupRequest::class,
            'pickup_request_shipments'
        )->withPivot([
            'added_at',
            'added_by',
            'removed_at',
            'removed_by',
            'status',
            'remarks',
        ])->withTimestamps();
    }

    public function deliveryAssignment(): HasOne
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
