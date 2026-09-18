<?php

namespace Modules\Merchant\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\Branch;

class MerchantChangeRequest extends Model
{
    /*
    |--------------------------------------------------------------------------
    | Table & Timestamps
    |--------------------------------------------------------------------------
    */

    protected $table = 'merchant_change_requests';

    protected $guarded = [];

    /*
    |--------------------------------------------------------------------------
    | Change Type Constants
    |--------------------------------------------------------------------------
    */

    public const TYPE_LOCATION = 'location';
    public const TYPE_DOCUMENTS = 'documents';
    public const TYPE_BANK_DETAILS = 'bank_details';
    public const TYPE_BUSINESS_PROFILE = 'business_profile';
    public const TYPE_CONTACT_INFO = 'contact_info';
    public const TYPE_MULTIPLE = 'multiple';

    public static function getChangeTypes(): array
    {
        return [
            self::TYPE_LOCATION,
            self::TYPE_DOCUMENTS,
            self::TYPE_BANK_DETAILS,
            self::TYPE_BUSINESS_PROFILE,
            self::TYPE_CONTACT_INFO,
            self::TYPE_MULTIPLE,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Status Constants
    |--------------------------------------------------------------------------
    */

    public const STATUS_PENDING = 'pending';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    public static function getValidStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_UNDER_REVIEW,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_CANCELLED,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Service Constants - What gets suspended based on change type
    |--------------------------------------------------------------------------
    */

    public const SERVICE_PICKUP = 'pickup';
    public const SERVICE_DELIVERY = 'delivery';
    public const SERVICE_SETTLEMENT = 'settlement';
    public const SERVICE_COMPLIANCE = 'compliance';
    public const SERVICE_TRACKING = 'tracking';
    public const SERVICE_SHIPMENT_CREATION = 'shipment_creation';

    /**
     * Map change types to affected services that will be suspended.
     */
    public static function getAffectedServicesForChangeType(string $changeType): array
    {
        return match ($changeType) {
            self::TYPE_LOCATION => [self::SERVICE_PICKUP, self::SERVICE_DELIVERY],
            self::TYPE_DOCUMENTS => [self::SERVICE_COMPLIANCE, self::SERVICE_SETTLEMENT],
            self::TYPE_BANK_DETAILS => [self::SERVICE_SETTLEMENT],
            self::TYPE_BUSINESS_PROFILE => [self::SERVICE_COMPLIANCE],
            self::TYPE_CONTACT_INFO => [],
            self::TYPE_MULTIPLE => [
                self::SERVICE_PICKUP,
                self::SERVICE_DELIVERY,
                self::SERVICE_SETTLEMENT,
                self::SERVICE_COMPLIANCE,
            ],
            default => [],
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Attribute Casts
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            'new_latitude' => 'float',
            'new_longitude' => 'float',
            'previous_latitude' => 'float',
            'previous_longitude' => 'float',
            'affected_services' => 'array',
            'new_documents' => 'array',
            'superseded_document_ids' => 'array',
            'requested_at' => 'datetime',
            'decision_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships - Merchant
    |--------------------------------------------------------------------------
    */

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships - Users
    |--------------------------------------------------------------------------
    */

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships - Branches (for location changes)
    |--------------------------------------------------------------------------
    */

    public function suggestedBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'suggested_branch_id');
    }

    public function suggestedSubBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'suggested_sub_branch_id');
    }

    public function approvedBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'approved_branch_id');
    }

    public function approvedSubBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'approved_sub_branch_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Status Query Scopes
    |--------------------------------------------------------------------------
    */

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeUnderReview($query)
    {
        return $query->where('status', self::STATUS_UNDER_REVIEW);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeRejected($query)
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING,
            self::STATUS_UNDER_REVIEW,
        ]);
    }

    public function scopeResolved($query)
    {
        return $query->whereIn('status', [
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
            self::STATUS_CANCELLED,
        ]);
    }

    public function scopeForMerchant($query, $merchantId)
    {
        return $query->where('merchant_id', $merchantId);
    }

    public function scopeByChangeType($query, string $changeType)
    {
        return $query->where('change_type', $changeType);
    }

    /*
    |--------------------------------------------------------------------------
    | Status Check Methods
    |--------------------------------------------------------------------------
    */

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isUnderReview(): bool
    {
        return $this->status === self::STATUS_UNDER_REVIEW;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isActive(): bool
    {
        return $this->isPending() || $this->isUnderReview();
    }

    public function isResolved(): bool
    {
        return !$this->isActive();
    }

    /*
    |--------------------------------------------------------------------------
    | Change Type Check Methods
    |--------------------------------------------------------------------------
    */

    public function isLocationChange(): bool
    {
        return $this->change_type === self::TYPE_LOCATION;
    }

    public function isDocumentChange(): bool
    {
        return $this->change_type === self::TYPE_DOCUMENTS;
    }

    public function isBankDetailsChange(): bool
    {
        return $this->change_type === self::TYPE_BANK_DETAILS;
    }

    public function isBusinessProfileChange(): bool
    {
        return $this->change_type === self::TYPE_BUSINESS_PROFILE;
    }

    public function isContactInfoChange(): bool
    {
        return $this->change_type === self::TYPE_CONTACT_INFO;
    }

    public function isMultipleChange(): bool
    {
        return $this->change_type === self::TYPE_MULTIPLE;
    }

    /*
    |--------------------------------------------------------------------------
    | Location Helpers
    |--------------------------------------------------------------------------
    */

    public function getNewLocation(): array
    {
        return [
            'address' => $this->new_address,
            'city' => $this->new_city,
            'area' => $this->new_area,
            'latitude' => $this->new_latitude,
            'longitude' => $this->new_longitude,
        ];
    }

    public function getPreviousLocation(): array
    {
        return [
            'address' => $this->previous_address,
            'city' => $this->previous_city,
            'area' => $this->previous_area,
            'latitude' => $this->previous_latitude,
            'longitude' => $this->previous_longitude,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Branch Helpers
    |--------------------------------------------------------------------------
    */

    public function getBranchForAssignment(): ?Branch
    {
        return $this->approved_branch_id
            ? $this->approvedBranch
            : $this->suggestedBranch;
    }

    public function getSubBranchForAssignment(): ?Branch
    {
        return $this->approved_sub_branch_id
            ? $this->approvedSubBranch
            : $this->suggestedSubBranch;
    }

    /*
    |--------------------------------------------------------------------------
    | Service Suspension Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Get the services that will be suspended for this change type.
     */
    public function getAffectedServices(): array
    {
        return $this->affected_services ?? [];
    }

    /**
     * Check if a specific service is affected by this change.
     */
    public function affectsService(string $service): bool
    {
        return in_array($service, $this->getAffectedServices());
    }

    /*
    |--------------------------------------------------------------------------
    | Timeline Helpers
    |--------------------------------------------------------------------------
    */

    public function getMinutesElapsed(): int
    {
        return (int) $this->requested_at->diffInMinutes(now());
    }

    public function hasDecision(): bool
    {
        return $this->decision_at !== null;
    }

    public function getTimeToDecision(): ?string
    {
        if (!$this->hasDecision()) {
            return null;
        }

        return $this->requested_at->diff($this->decision_at)->format('%h hours %i minutes');
    }

    /*
    |--------------------------------------------------------------------------
    | Diff Helpers for Admin Review
    |--------------------------------------------------------------------------
    */

    /**
     * Get summary of what changed (for admin review).
     */
    public function getChangeSummary(): array
    {
        $summary = [];

        if ($this->isLocationChange() || $this->isMultipleChange()) {
            if ($this->new_address) {
                $summary['location'] = [
                    'previous' => $this->getPreviousLocation(),
                    'new' => $this->getNewLocation(),
                ];
            }
        }

        if ($this->isBusinessProfileChange() || $this->isMultipleChange()) {
            $profileChanges = [];
            if ($this->new_business_name && $this->new_business_name !== $this->previous_business_name) {
                $profileChanges['name'] = ['previous' => $this->previous_business_name, 'new' => $this->new_business_name];
            }
            if ($this->new_business_type && $this->new_business_type !== $this->previous_business_type) {
                $profileChanges['type'] = ['previous' => $this->previous_business_type, 'new' => $this->new_business_type];
            }
            if ($this->new_pan_vat_number && $this->new_pan_vat_number !== $this->previous_pan_vat_number) {
                $profileChanges['pan_vat'] = ['previous' => $this->previous_pan_vat_number, 'new' => $this->new_pan_vat_number];
            }
            if (count($profileChanges) > 0) {
                $summary['business_profile'] = $profileChanges;
            }
        }

        if ($this->isBankDetailsChange() || $this->isMultipleChange()) {
            $bankChanges = [];
            if ($this->new_bank_name && $this->new_bank_name !== $this->previous_bank_name) {
                $bankChanges['name'] = ['previous' => $this->previous_bank_name, 'new' => $this->new_bank_name];
            }
            if ($this->new_bank_account_number && $this->new_bank_account_number !== $this->previous_bank_account_number) {
                $bankChanges['account_number'] = ['previous' => '****' . substr($this->previous_bank_account_number, -4), 'new' => '****' . substr($this->new_bank_account_number, -4)];
            }
            if (count($bankChanges) > 0) {
                $summary['bank_details'] = $bankChanges;
            }
        }

        if ($this->isContactInfoChange() || $this->isMultipleChange()) {
            $contactChanges = [];
            if ($this->new_contact_person && $this->new_contact_person !== $this->previous_contact_person) {
                $contactChanges['person'] = ['previous' => $this->previous_contact_person, 'new' => $this->new_contact_person];
            }
            if ($this->new_phone && $this->new_phone !== $this->previous_phone) {
                $contactChanges['phone'] = ['previous' => $this->previous_phone, 'new' => $this->new_phone];
            }
            if (count($contactChanges) > 0) {
                $summary['contact_info'] = $contactChanges;
            }
        }

        if ($this->isDocumentChange() || $this->isMultipleChange()) {
            if ($this->new_documents && count($this->new_documents) > 0) {
                $summary['documents'] = [
                    'superseded_count' => count($this->superseded_document_ids ?? []),
                    'new_count' => count($this->new_documents),
                ];
            }
        }

        return $summary;
    }
}
