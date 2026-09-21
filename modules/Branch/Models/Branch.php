<?php

namespace Modules\Branch\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Branch\Enums\BranchStatus;
use Modules\Branch\Enums\BranchType;

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

        'account_invitation_status',
        'account_invitation_email',
        'account_invitation_queued_at',
        'account_invitation_sent_at',
        'account_invitation_failed_at',
        'account_invitation_error',
        'account_invitation_count',
    ];

    protected $casts = [
        'type' => BranchType::class,
        'status' => BranchStatus::class,
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

        'account_invitation_queued_at' => 'datetime',
        'account_invitation_sent_at' => 'datetime',
        'account_invitation_failed_at' => 'datetime',

        'account_invitation_count' => 'integer',
    ];

    /** @deprecated use BranchType enum */
    public const TYPE_HEAD_BRANCH = BranchType::HeadBranch->value;
    public const TYPE_FRANCHISE_BRANCH = BranchType::FranchiseBranch->value;
    public const TYPE_SUB_BRANCH = BranchType::SubBranch->value;
    public const TYPE_PICKUP_POINT = BranchType::PickupPoint->value;
    public const TYPE_DELIVERY_HUB = BranchType::DeliveryHub->value;

    /** @deprecated use BranchStatus enum */
    public const STATUS_DRAFT = BranchStatus::Draft->value;
    public const STATUS_PENDING_REVIEW = BranchStatus::PendingReview->value;
    public const STATUS_APPROVED = BranchStatus::Approved->value;
    public const STATUS_ACTIVE = BranchStatus::Active->value;
    public const STATUS_SUSPENDED = BranchStatus::Suspended->value;
    public const STATUS_REJECTED = BranchStatus::Rejected->value;
    public const STATUS_CLOSED = BranchStatus::Closed->value;

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
        return $query->where('status', BranchStatus::Active);
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
        return $query->where('type', BranchType::SubBranch);
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
        $type = $this->type instanceof BranchType
            ? $this->type
            : BranchType::resolve($this->type);

        return $type->isMainBranch();
    }

    public function isActive(): bool
    {
        $status = $this->status instanceof BranchStatus
            ? $this->status
            : BranchStatus::resolve($this->status);

        return $status->isOperable();
    }

    public function getIsSubBranchAttribute(): bool
    {
        $type = $this->type instanceof BranchType ? $this->type : BranchType::resolve($this->type);

        return $type->isSubBranch();
    }
}
