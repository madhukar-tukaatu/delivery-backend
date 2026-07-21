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
        'coverage_location_id',

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

        /*
         * Assigned coverage point used by old routing/pricing.
         */
        'latitude',
        'longitude',
        'coverage_radius_km',

        /*
         * Real physical branch office location.
         */
        'office_address',
        'office_city',
        'office_area',
        'office_street',
        'office_landmark',
        'office_latitude',
        'office_longitude',

        'covered_areas',
        'opening_time',
        'closing_time',
        'operating_days',
        'daily_shipment_capacity',

        'pickup_enabled',
        'delivery_enabled',
        'pod_enabled',
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
        'pod_enabled' => 'boolean',
        'return_enabled' => 'boolean',

        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'coverage_radius_km' => 'decimal:2',

        'office_latitude' => 'decimal:7',
        'office_longitude' => 'decimal:7',

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

    public function coverageLocation(): BelongsTo
    {
        return $this->belongsTo(CoverageLocation::class, 'coverage_location_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(BranchDocument::class, 'branch_id');
    }

    public function agreements(): HasMany
    {
        return $this->hasMany(BranchAgreement::class, 'branch_id');
    }
    public function users(): HasMany
    {
        return $this->hasMany(
            User::class,
            'branch_id'
        );
    }

    public function teamPositions(): HasMany
    {
        return $this->hasMany(
            BranchTeamPosition::class,
            'branch_id'
        );
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

    public function scopeMainBranches(Builder $query): Builder
    {
        return $query->whereIn('type', [
            self::TYPE_HEAD_BRANCH,
            self::TYPE_FRANCHISE_BRANCH,
        ]);
    }

    public function scopeSubBranches(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_SUB_BRANCH);
    }

    public function getFullAddressAttribute(): string
    {
        return collect([
            $this->address,
            $this->area,
            $this->city,
            $this->district,
            $this->province,
            $this->country,
        ])->filter()->implode(', ');
    }

    public function getOfficeFullAddressAttribute(): string
    {
        return collect([
            $this->office_address,
            $this->office_area,
            $this->office_city,
            $this->district,
            $this->province,
            $this->country,
        ])->filter()->implode(', ');
    }

    public function getIsMainBranchAttribute(): bool
    {
        return in_array($this->type, [
            self::TYPE_HEAD_BRANCH,
            self::TYPE_FRANCHISE_BRANCH,
        ], true);
    }

    public function getIsSubBranchAttribute(): bool
    {
        return $this->type === self::TYPE_SUB_BRANCH;
    }
}
