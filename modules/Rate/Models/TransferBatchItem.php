<?php

namespace Modules\Rate\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TransferBatchItem extends Model
{
    protected $table = 'transfer_batch_items';

    protected $fillable = [
        'transfer_batch_id',
        'shipment_id',
        'zone_id',
        'sort_order',
        'status',
        'delivery_instructions',
    ];

    protected $casts = [
        'transfer_batch_id'  => 'integer',
        'shipment_id'        => 'integer',
        'zone_id'            => 'integer',
        'sort_order'         => 'integer',
    ];

    // Relationships
    public function batch(): BelongsTo
    {
        return $this->belongsTo(TransferBatch::class, 'transfer_batch_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(
            \Modules\Shipment\Models\Shipment::class,
            'shipment_id'
        );
    }

    // Scopes
    public function scopeByBatch(Builder $query, int $batchId): Builder
    {
        return $query->where('transfer_batch_id', $batchId);
    }

    public function scopeByZone(Builder $query, int $zoneId): Builder
    {
        return $query->where('zone_id', $zoneId);
    }

    public function scopeSorted(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }

    // Methods
    public function getDeliveryZone()
    {
        return $this->zone_id ? DeliveryZone::find($this->zone_id) : null;
    }
}
