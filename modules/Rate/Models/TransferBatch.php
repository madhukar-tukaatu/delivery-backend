<?php

namespace Modules\Rate\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Branch\Models\CoverageLocation;

final class TransferBatch extends Model
{
    protected $table = 'transfer_batches';

    protected $fillable = [
        'batch_code',
        'from_branch_id',
        'to_branch_id',
        'branch_transfer_route_id',
        'status',
        'item_count',
        'scheduled_at',
        'dispatched_at',
        'delivered_at',
        'notes',
    ];

    protected $casts = [
        'from_branch_id'              => 'integer',
        'to_branch_id'                => 'integer',
        'branch_transfer_route_id'    => 'integer',
        'item_count'                  => 'integer',
        'scheduled_at'                => 'datetime',
        'dispatched_at'               => 'datetime',
        'delivered_at'                => 'datetime',
    ];

    // Relationships
    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(CoverageLocation::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(CoverageLocation::class, 'to_branch_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(BranchTransferRoute::class, 'branch_transfer_route_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransferBatchItem::class, 'transfer_batch_id');
    }

    // Scopes
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', 'confirmed');
    }

    public function scopeSorted(Builder $query): Builder
    {
        return $query->where('status', 'sorted');
    }

    public function scopeDispatched(Builder $query): Builder
    {
        return $query->where('status', 'dispatched');
    }

    public function scopeInTransit(Builder $query): Builder
    {
        return $query->where('status', 'in_transit');
    }

    public function scopeDelivered(Builder $query): Builder
    {
        return $query->where('status', 'delivered');
    }

    public function scopeFromBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('from_branch_id', $branchId);
    }

    public function scopeToBranch(Builder $query, int $branchId): Builder
    {
        return $query->where('to_branch_id', $branchId);
    }

    // Methods
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function canDispatch(): bool
    {
        return $this->status === 'sorted' && $this->item_count > 0;
    }

    public function getItemsByZone(int $zoneId): \Illuminate\Database\Eloquent\Collection
    {
        return $this->items()->where('zone_id', $zoneId)->orderBy('sort_order')->get();
    }
}
