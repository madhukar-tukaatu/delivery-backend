<?php

namespace Modules\Merchant\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use Modules\Branch\Models\Branch;
use Modules\Shipment\Models\Shipment;

class Merchant extends Model
{
    protected $guarded = [];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function apiKeys()
    {
        return $this->hasMany(MerchantApiKey::class);
    }

    public function webhooks()
    {
        return $this->hasMany(MerchantWebhook::class);
    }

    public function pickupLocations()
    {
        return $this->hasMany(MerchantPickupLocation::class);
    }

    public function shipments()
    {
        return $this->hasMany(Shipment::class);
    }

    public function defaultBranch()
    {
        return $this->belongsTo(Branch::class, 'default_branch_id');
    }

    public function defaultSubBranch()
    {
        return $this->belongsTo(Branch::class, 'default_sub_branch_id');
    }

    public function documents()
    {
        return $this->hasMany(\Modules\Merchant\Models\MerchantDocument::class);
    }
    // public function pickupLocations()
    // {
    //     return $this->hasMany(\Modules\Merchant\Models\MerchantPickupLocation::class);
    // }
    // public function defaultBranch()
    // {
    //     return $this->belongsTo(\Modules\Branch\Models\Branch::class, 'default_branch_id');
    // }
    // public function defaultSubBranch()
    // {
    //     return $this->belongsTo(\Modules\Branch\Models\Branch::class, 'default_sub_branch_id');
    // }
    public function suggestedBranch()
    {
        return $this->belongsTo(\Modules\Branch\Models\Branch::class, 'suggested_branch_id');
    }
    public function suggestedSubBranch()
    {
        return $this->belongsTo(\Modules\Branch\Models\Branch::class, 'suggested_sub_branch_id');
    }
    // public function apiKeys()
    // {
    //     return $this->hasMany(\Modules\Merchant\Models\MerchantApiKey::class);
    // }
}
