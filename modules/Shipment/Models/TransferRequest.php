<?php

declare(strict_types=1);

namespace Modules\Shipment\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\Branch;
use Modules\Dispatch\Models\DispatchManifest;

final class TransferRequest extends Model
{
    protected $table = 'transfer_requests';

    protected $fillable = [
        'request_number',
        'origin_branch_id',
        'destination_hub_id',
        'parcel_count',
        'total_weight_kg',
        'requested_vehicle_type',
        'urgency',
        'threshold_exceeded',
        'status',
        'notes',
        'dispatch_manifest_id',
        'created_by_user_id',
        'handled_by_user_id',
        'handled_at',
    ];

    protected $casts = [
        'origin_branch_id'     => 'integer',
        'destination_hub_id'   => 'integer',
        'parcel_count'         => 'integer',
        'total_weight_kg'      => 'decimal:2',
        'threshold_exceeded'   => 'boolean',
        'dispatch_manifest_id' => 'integer',
        'created_by_user_id'   => 'integer',
        'handled_by_user_id'   => 'integer',
        'handled_at'           => 'datetime',
    ];

    public function originBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'origin_branch_id');
    }

    public function destinationHub(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'destination_hub_id');
    }

    public function manifest(): BelongsTo
    {
        return $this->belongsTo(DispatchManifest::class, 'dispatch_manifest_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function handledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by_user_id');
    }
}
