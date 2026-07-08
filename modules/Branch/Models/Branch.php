<?php

namespace Modules\Branch\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    protected $fillable = [
        'parent_id',
        'type',
        'name',
        'code',
        'legal_name',
        'owner_name',
        'contact_person',
        'email',
        'phone',
        'alternative_phone',
        'pan_vat_number',
        'registration_number',
        'business_type',
        'status',
        'country',
        'province',
        'district',
        'city',
        'area',
        'address',
        'landmark',
        'latitude',
        'longitude',
        'coverage_radius_km',
        'covered_areas',
        'opening_time',
        'closing_time',
        'operating_days',
        'daily_shipment_capacity',
        'pickup_enabled',
        'delivery_enabled',
        'cod_enabled',
        'return_enabled',
        'manager_user_id',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
    ];

    protected $casts = [
        'covered_areas' => 'array',
        'operating_days' => 'array',
        'pickup_enabled' => 'boolean',
        'delivery_enabled' => 'boolean',
        'cod_enabled' => 'boolean',
        'return_enabled' => 'boolean',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'coverage_radius_km' => 'decimal:2',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public const TYPE_HEAD_BRANCH = 'head_branch';
    public const TYPE_FRANCHISE_BRANCH = 'franchise_branch';
    public const TYPE_SUB_BRANCH = 'sub_branch';
    public const TYPE_PICKUP_POINT = 'pickup_point';
    public const TYPE_DELIVERY_HUB = 'delivery_hub';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CLOSED = 'closed';

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(BranchDocument::class, 'branch_id');
    }

    public function agreements(): HasMany
    {
        return $this->hasMany(BranchAgreement::class, 'branch_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeType(Builder $query, ?string $type): Builder
    {
        return $type ? $query->where('type', $type) : $query;
    }

    public function getFullAddressAttribute(): string
    {
        return collect([$this->address, $this->area, $this->city, $this->district, $this->province, $this->country])
            ->filter()
            ->implode(', ');
    }
}
