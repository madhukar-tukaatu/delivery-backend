<?php

namespace Modules\Merchant\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantDocument;
use Modules\Merchant\Models\MerchantPickupLocation;
use Modules\Merchant\Models\MerchantApiKey;
use Modules\Rate\Models\MerchantRateCard;
use Modules\Rate\Models\RateCard;
use Modules\Routing\Services\BranchLocatorService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;


class MerchantOnboardingService
{
    public function __construct(private BranchLocatorService $branchLocator) {}

    public function updateBusinessProfile(Merchant $merchant, array $data): Merchant
    {
        $merchant->update([
            'name' => $data['business_name'] ?? $merchant->name,
            'business_type' => $data['business_type'] ?? null,
            'pan_vat_number' => $data['pan_vat_number'] ?? null,
            'website_url' => $data['website_url'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'area' => $data['area'] ?? null,
            'verification_status' => 'business_profile_completed',
        ]);

        return $merchant->fresh();
    }

    public function updatePickupLocation(Merchant $merchant, array $data): MerchantPickupLocation
    {
        return DB::transaction(function () use ($merchant, $data) {
            $location = $this->branchLocator->locate((float) $data['latitude'], (float) $data['longitude']);
            $branch = $location['branch'];
            $subBranch = $location['sub_branch'];

            $merchant->update([
                'pickup_address' => $data['address'],
                'pickup_city' => $data['city'] ?? null,
                'pickup_area' => $data['area'] ?? null,
                'pickup_lat' => $data['latitude'],
                'pickup_lng' => $data['longitude'],
                'suggested_branch_id' => $branch?->id,
                'suggested_sub_branch_id' => $subBranch?->id,
                'verification_status' => 'pickup_location_completed',
            ]);

            $pickupLocation = MerchantPickupLocation::updateOrCreate([
                'merchant_id' => $merchant->id,
                'is_default' => true,
            ], [
                'name' => $data['name'] ?? 'Default Pickup Location',
                'contact_person' => $data['contact_person'] ?? $merchant->contact_person,
                'phone' => $data['phone'] ?? $merchant->phone,
                'address' => $data['address'],
                'city' => $data['city'] ?? null,
                'area' => $data['area'] ?? null,
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'suggested_branch_id' => $branch?->id,
                'suggested_sub_branch_id' => $subBranch?->id,
                'branch_id' => null,
                'sub_branch_id' => null,
                'status' => 'pending_verification',
            ]);

            return $pickupLocation->fresh();
        });
    }

    public function updateBankDetails(Merchant $merchant, array $data): Merchant
    {
        $merchant->update([
            'bank_name' => $data['bank_name'] ?? null,
            'bank_account_name' => $data['bank_account_name'] ?? null,
            'bank_account_number' => $data['bank_account_number'] ?? null,
            'bank_branch' => $data['bank_branch'] ?? null,
            'verification_status' => 'bank_details_completed',
        ]);

        return $merchant->fresh();
    }

    public function uploadDocument(Merchant $merchant, string $type, UploadedFile $file): MerchantDocument
    {
        $path = $file->store("merchant-documents/{$merchant->id}", 'public');

        return MerchantDocument::updateOrCreate([
            'merchant_id' => $merchant->id,
            'document_type' => $type,
        ], [
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'status' => 'pending',
            'remarks' => null,
            'verified_by' => null,
            'verified_at' => null,
        ]);
    }

    public function submitForReview(Merchant $merchant): Merchant
    {
        $required = ['business_registration', 'pan_vat', 'owner_id', 'bank_proof'];
        $uploaded = $merchant->documents()->pluck('document_type')->all();
        $missing = array_values(array_diff($required, $uploaded));

        if ($missing) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'documents' => 'Missing required documents: ' . implode(', ', $missing),
            ]);
        }

        if (!$merchant->pickup_lat || !$merchant->pickup_lng) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'pickup_location' => 'Pickup location is required.',
            ]);
        }

        $merchant->update([
            'status' => 'pending_verification',
            'verification_status' => 'submitted',
            'more_info_message' => null,
            'rejected_reason' => null,
        ]);

        return $merchant->fresh(['documents']);
    }

    // public function approve(Merchant $merchant, User $admin, array $data): Merchant
    // {
    //     return DB::transaction(function () use ($merchant, $admin, $data) {
    //         $branchId = $data['branch_id'] ?? $merchant->suggested_branch_id;
    //         $subBranchId = $data['sub_branch_id'] ?? $merchant->suggested_sub_branch_id;

    //         if (!$branchId) {
    //             throw \Illuminate\Validation\ValidationException::withMessages([
    //                 'branch_id' => 'Branch is required before approval.',
    //             ]);
    //         }

    //         $merchant->update([
    //             'default_branch_id' => $branchId,
    //             'default_sub_branch_id' => $subBranchId,
    //             'status' => 'active',
    //             'verification_status' => 'approved',
    //             'verified_by' => $admin->id,
    //             'verified_at' => now(),
    //             'rejected_reason' => null,
    //             'more_info_message' => null,
    //         ]);

    //         $merchant->documents()->where('status', 'pending')->update([
    //             'status' => 'approved',
    //             'verified_by' => $admin->id,
    //             'verified_at' => now(),
    //         ]);

    //         $merchant->pickupLocations()->where('is_default', true)->update([
    //             'branch_id' => $branchId,
    //             'sub_branch_id' => $subBranchId,
    //             'status' => 'active',
    //         ]);

    //         User::where('merchant_id', $merchant->id)->update(['is_active' => true]);

    //         $this->ensureDefaultRateCard($merchant);
    //         $this->ensureApiKey($merchant);

    //         return $merchant->fresh(['documents', 'pickupLocations']);
    //     });
    // }

    public function approve(Merchant $merchant, User $admin, array $data): Merchant
    {
        return DB::transaction(function () use ($merchant, $admin, $data) {
            $branchId = $data['branch_id'] ?? $merchant->suggested_branch_id;
            $subBranchId = $data['sub_branch_id'] ?? $merchant->suggested_sub_branch_id;

            if (!$branchId) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'branch_id' => 'Branch is required before approval.',
                ]);
            }

            $merchantUpdate = [
                'default_branch_id' => $branchId,
                'default_sub_branch_id' => $subBranchId,
                'status' => 'active',
                'verification_status' => 'approved',
                'rejected_reason' => null,
                'more_info_message' => null,
            ];

            if (Schema::hasColumn('merchants', 'verified_by')) {
                $merchantUpdate['verified_by'] = $admin->id;
            }

            if (Schema::hasColumn('merchants', 'verified_at')) {
                $merchantUpdate['verified_at'] = now();
            }

            $merchant->forceFill($merchantUpdate)->save();

            $merchant->documents()->where('status', 'pending')->update([
                'status' => 'approved',
                'verified_by' => $admin->id,
                'verified_at' => now(),
            ]);

            $merchant->pickupLocations()->where('is_default', true)->update([
                'branch_id' => $branchId,
                'sub_branch_id' => $subBranchId,
                'status' => 'active',
            ]);

            User::where('merchant_id', $merchant->id)->update([
                'is_active' => true,
            ]);

            $this->ensureDefaultRateCard($merchant);

            $this->ensureSingleApiKey($merchant);

            return $merchant->fresh([
                'documents',
                'pickupLocations',
                'apiKeys',
            ]);
        });
    }
    public function reject(Merchant $merchant, User $admin, string $reason): Merchant
    {
        $merchant->update([
            'status' => 'rejected',
            'verification_status' => 'rejected',
            'rejected_reason' => $reason,
            'verified_by' => $admin->id,
            'verified_at' => now(),
        ]);

        return $merchant->fresh();
    }

    public function requestMoreInfo(Merchant $merchant, string $message): Merchant
    {
        $merchant->update([
            'status' => 'more_info_required',
            'verification_status' => 'more_info_required',
            'more_info_message' => $message,
        ]);

        return $merchant->fresh();
    }

    private function ensureDefaultRateCard(Merchant $merchant): void
    {
        $rateCard = RateCard::where('status', 'active')->first();
        if (!$rateCard) {
            return;
        }

        MerchantRateCard::firstOrCreate([
            'merchant_id' => $merchant->id,
            'rate_card_id' => $rateCard->id,
        ], [
            'is_default' => true,
        ]);
    }

    // private function ensureApiKey(Merchant $merchant): void
    // {
    //     if (MerchantApiKey::where('merchant_id', $merchant->id)->exists()) {
    //         return;
    //     }

    //     MerchantApiKey::create([
    //         'merchant_id' => $merchant->id,
    //         'name' => 'Default Live Key',
    //         'api_key' => 'mk_' . bin2hex(random_bytes(16)),
    //         'api_secret_hash' => \Illuminate\Support\Facades\Hash::make(bin2hex(random_bytes(24))),
    //         'environment' => 'live',
    //         'permissions' => ['shipments:create', 'shipments:read', 'rates:calculate'],
    //         'status' => 'active',
    //     ]);
    // }


    // private function ensureApiKey(Merchant $merchant): void
    // {
    //     $existingActiveLiveKey = MerchantApiKey::query()
    //         ->where('merchant_id', $merchant->id)
    //         ->where('environment', 'live')
    //         ->where(function ($query) {
    //             $query->where('status', 'active')
    //                 ->orWhere('is_active', true);
    //         })
    //         ->first();

    //     if ($existingActiveLiveKey) {
    //         return;
    //     }

    //     $plainApiKey = 'tk_live_' . Str::random(40);
    //     $plainApiSecret = 'ts_live_' . Str::random(60);

    //     MerchantApiKey::create([
    //         'merchant_id' => $merchant->id,
    //         'name' => 'Default Live API Key',

    //         'api_key' => $plainApiKey,
    //         'api_key_hash' => hash('sha256', $plainApiKey),

    //         'api_secret_hash' => Hash::make($plainApiSecret),
    //         'api_secret_encrypted' => Crypt::encryptString($plainApiSecret),

    //         'environment' => 'live',
    //         'permissions' => [
    //             'pricing.quote',
    //             'shipments.create',
    //             'shipments.track',
    //         ],

    //         'status' => 'active',
    //         'is_active' => true,
    //         'expires_at' => null,
    //     ]);
    // }

    private function ensureSingleApiKey(Merchant $merchant): MerchantApiKey
    {
        $existingKey = MerchantApiKey::query()
            ->where('merchant_id', $merchant->id)
            ->first();

        if ($existingKey) {
            $update = [
                'status' => 'active',
                'environment' => $existingKey->environment ?: 'live',
            ];

            if (Schema::hasColumn('merchant_api_keys', 'is_active')) {
                $update['is_active'] = true;
            }

            $existingKey->forceFill($update)->save();

            return $existingKey->fresh();
        }

        $plainApiKey = 'tk_live_' . Str::random(40);
        $plainApiSecret = 'ts_live_' . Str::random(60);

        $payload = [
            'merchant_id' => $merchant->id,
            'name' => 'Default Live API Key',
            'api_key' => $plainApiKey,
            'api_secret_hash' => Hash::make($plainApiSecret),
            'environment' => 'live',
            'permissions' => [
                'pricing.quote',
                'shipments.create',
                'shipments.track',
            ],
            'status' => 'active',
        ];

        if (Schema::hasColumn('merchant_api_keys', 'api_key_hash')) {
            $payload['api_key_hash'] = hash('sha256', $plainApiKey);
        }

        if (Schema::hasColumn('merchant_api_keys', 'api_secret_encrypted')) {
            $payload['api_secret_encrypted'] = Crypt::encryptString($plainApiSecret);
        }

        if (Schema::hasColumn('merchant_api_keys', 'is_active')) {
            $payload['is_active'] = true;
        }

        if (Schema::hasColumn('merchant_api_keys', 'expires_at')) {
            $payload['expires_at'] = null;
        }

        return MerchantApiKey::create($payload);
    }
}
