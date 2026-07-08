<?php

namespace Modules\Merchant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Branch\Models\Branch;

class MerchantPickupLocation extends Model
{
    protected $fillable = [
        'merchant_id',
        'branch_id',
        'sub_branch_id',
        'name',
        'contact_person',
        'phone',
        'address',
        'city',
        'area',
        'latitude',
        'longitude',
        'is_default',
        'status',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'merchant_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function subBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'sub_branch_id');
    }

    // Optional alias if somewhere your code uses sub_branch
    public function sub_branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'sub_branch_id');
    }
}