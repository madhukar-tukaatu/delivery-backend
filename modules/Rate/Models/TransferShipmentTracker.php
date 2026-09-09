<?php

namespace Modules\Rate\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\CoverageLocation;

final class TransferShipmentTracker extends Model
{
    protected $table = 'transfer_shipment_trackers';

    protected $fillable = [
        'shipment_id',
        'branch_transfer_route_id',
        'current_leg',
        'current_branch_id',
        'status',
        'leg_history',
        'completed_at',
    ];

    protected $casts = [
        'shipment_id'              => 'integer',
        'branch_transfer_route_id' => 'integer',
        'current_leg'              => 'integer',
        'current_branch_id'        => 'integer',
        'leg_history'              => 'json',
        'completed_at'             => 'datetime',
    ];

    // Relationships
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(
            \Modules\Shipment\Models\Shipment::class,
            'shipment_id'
        );
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(BranchTransferRoute::class, 'branch_transfer_route_id');
    }

    public function currentBranch(): BelongsTo
    {
        return $this->belongsTo(CoverageLocation::class, 'current_branch_id');
    }

    // Scopes
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['in_consolidation', 'in_transit', 'at_hub', 'out_for_delivery']);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'delivered');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    public function scopeNotStarted(Builder $query): Builder
    {
        return $query->where('status', 'not_started');
    }

    // Methods
    public function isCompleted(): bool
    {
        return $this->status === 'delivered' || $this->status === 'failed';
    }

    public function canTransition(string $newStatus): bool
    {
        $validTransitions = [
            'not_started'        => ['picked_up'],
            'picked_up'          => ['in_consolidation'],
            'in_consolidation'   => ['in_transit'],
            'in_transit'         => ['at_hub', 'out_for_delivery'],
            'at_hub'             => ['out_for_delivery', 'in_transit'],
            'out_for_delivery'   => ['delivered', 'failed'],
            'delivered'          => [],
            'failed'             => ['out_for_delivery'],
        ];

        return in_array($newStatus, $validTransitions[$this->status] ?? []);
    }

    public function transition(string $newStatus): bool
    {
        if (!$this->canTransition($newStatus)) {
            return false;
        }

        $history = $this->leg_history ?? [];
        $history[] = [
            'status'       => $this->status,
            'transitioned' => now(),
        ];

        $this->update([
            'status'       => $newStatus,
            'leg_history'  => $history,
            'completed_at' => in_array($newStatus, ['delivered', 'failed']) ? now() : null,
        ]);

        return true;
    }
}
