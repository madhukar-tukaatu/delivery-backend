<?php

namespace Modules\Branch\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CoverageLocation extends Model
{
    protected $fillable = [
        'name',
        'code',
        'type',
        'parent_id',
        'branch_id',

        'country',
        'province',
        'district',
        'city',
        'area',
        'street',
        'address',
        'landmark',

        'latitude',
        'longitude',
        'coverage_radius_km',

        'is_hq_managed',
        'status',
        'notes',

        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'coverage_radius_km' => 'decimal:2',
        'is_hq_managed' => 'boolean',
    ];

    public const TYPE_MAIN_BRANCH_ZONE = 'main_branch_zone';
    public const TYPE_SUB_BRANCH_ZONE = 'sub_branch_zone';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function assignedBranches(): HasMany
    {
        return $this->hasMany(Branch::class, 'coverage_location_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
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
}