<?php

namespace Modules\Merchant\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Branch\Models\Branch;
use Modules\Shipment\Models\Shipment;

class Merchant extends Model
{
    /*
    |--------------------------------------------------------------------------
    | Application Sources
    |--------------------------------------------------------------------------
    */

    public const SOURCE_PUBLIC_WEBSITE = 'public_website';
    public const SOURCE_STORE_MANAGER = 'store_manager';
    public const SOURCE_ADMIN = 'admin';

    /*
    |--------------------------------------------------------------------------
    | Mass Assignment
    |--------------------------------------------------------------------------
    |
    | Your existing services use Merchant::create(), update() and forceFill().
    | Keeping guarded empty preserves your current public merchant workflow.
    |
    */

    protected $guarded = [];

    /*
    |--------------------------------------------------------------------------
    | Hidden Attributes
    |--------------------------------------------------------------------------
    |
    | Prevent the decrypted callback secret from being returned through
    | Merchant API responses or admin application responses.
    |
    */

    protected $hidden = [
        'integration_callback_secret',
    ];

    /*
    |--------------------------------------------------------------------------
    | Attribute Casts
    |--------------------------------------------------------------------------
    */

    protected function casts(): array
    {
        return [
            /*
             * Store integration JSON fields
             */
            'requested_services' => 'array',
            'approved_services' => 'array',
            'integration_payload' => 'array',

            /*
             * Laravel encrypts this before saving and decrypts it
             * only when accessed through the model.
             */
            'integration_callback_secret' => 'encrypted',

            /*
             * Date fields
             */
            'submitted_at' => 'datetime',
            'integration_approved_at' => 'datetime',
            'integration_callback_sent_at' => 'datetime',
            'verified_at' => 'datetime',

            /*
             * Location values
             */
            'pickup_lat' => 'float',
            'pickup_lng' => 'float',

            /*
             * Foreign keys
             */
            'default_branch_id' => 'integer',
            'default_sub_branch_id' => 'integer',
            'suggested_branch_id' => 'integer',
            'suggested_sub_branch_id' => 'integer',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | User Relationships
    |--------------------------------------------------------------------------
    */

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /*
    |--------------------------------------------------------------------------
    | API Integration Relationships
    |--------------------------------------------------------------------------
    */

    public function apiKeys(): HasMany
    {
        return $this->hasMany(MerchantApiKey::class);
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(MerchantWebhook::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Pickup Locations
    |--------------------------------------------------------------------------
    */

    public function pickupLocations(): HasMany
    {
        return $this->hasMany(MerchantPickupLocation::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Merchant Documents
    |--------------------------------------------------------------------------
    */

    public function documents(): HasMany
    {
        return $this->hasMany(MerchantDocument::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Shipment Relationships
    |--------------------------------------------------------------------------
    */

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Assigned Branch Relationships
    |--------------------------------------------------------------------------
    */

    public function defaultBranch(): BelongsTo
    {
        return $this->belongsTo(
            Branch::class,
            'default_branch_id'
        );
    }

    public function defaultSubBranch(): BelongsTo
    {
        return $this->belongsTo(
            Branch::class,
            'default_sub_branch_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Suggested Branch Relationships
    |--------------------------------------------------------------------------
    */

    public function suggestedBranch(): BelongsTo
    {
        return $this->belongsTo(
            Branch::class,
            'suggested_branch_id'
        );
    }

    public function suggestedSubBranch(): BelongsTo
    {
        return $this->belongsTo(
            Branch::class,
            'suggested_sub_branch_id'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Source Helpers
    |--------------------------------------------------------------------------
    */

    public function isStoreManagerApplication(): bool
    {
        return $this->application_source ===
            self::SOURCE_STORE_MANAGER;
    }

    public function isPublicWebsiteApplication(): bool
    {
        return $this->application_source ===
            self::SOURCE_PUBLIC_WEBSITE;
    }

    public function isAdminCreated(): bool
    {
        return $this->application_source ===
            self::SOURCE_ADMIN;
    }
}