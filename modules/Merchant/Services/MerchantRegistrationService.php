<?php

namespace Modules\Merchant\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Models\MerchantDocument;
use Modules\Routing\Services\BranchLocatorService;

class MerchantRegistrationService
{
    public function __construct(private BranchLocatorService $branchLocator) {}

    public function register(array $data, array $documents = []): Merchant
    {
        return DB::transaction(function () use ($data, $documents) {
            $suggested = null;

            if (!empty($data['pickup_lat']) && !empty($data['pickup_lng'])) {
                $suggested = $this->branchLocator->nearestBranchSet((float) $data['pickup_lat'], (float) $data['pickup_lng']);
            }

            $merchant = Merchant::create([
                'name' => $data['name'],
                'code' => $this->makeCode($data['name']),
                'owner_name' => $data['owner_name'],
                'contact_person' => $data['contact_person'] ?? $data['owner_name'],
                'phone' => $data['phone'],
                'email' => $data['email'],
                'website_url' => $data['website_url'] ?? null,
                'business_type' => $data['business_type'] ?? null,
                'pan_vat_number' => $data['pan_vat_number'] ?? null,
                'address' => $data['address'] ?? null,
                'pickup_address' => $data['pickup_address'] ?? $data['address'] ?? null,
                'pickup_city' => $data['pickup_city'] ?? null,
                'pickup_area' => $data['pickup_area'] ?? null,
                'pickup_lat' => $data['pickup_lat'] ?? null,
                'pickup_lng' => $data['pickup_lng'] ?? null,
                'suggested_branch_id' => $suggested['branch']->id ?? null,
                'suggested_sub_branch_id' => $suggested['sub_branch']->id ?? null,
                'default_branch_id' => null,
                'default_sub_branch_id' => null,
                'bank_name' => $data['bank_name'] ?? null,
                'bank_account_name' => $data['bank_account_name'] ?? null,
                'bank_account_number' => $data['bank_account_number'] ?? null,
                'status' => 'pending',
                'verification_status' => 'pending',
            ]);

            $user = User::create([
                'name' => $data['contact_person'] ?? $data['owner_name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'role' => 'merchant',
                'merchant_id' => $merchant->id,
                'password' => Hash::make($data['password']),
                'is_active' => false,
            ]);
            $user->syncRoles(['merchant']);

            foreach ($documents as $type => $file) {
                if ($file instanceof UploadedFile) {
                    $path = $file->store('merchant-documents/'.$merchant->id, 'public');
                    MerchantDocument::create([
                        'merchant_id' => $merchant->id,
                        'document_type' => $type,
                        'file_path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getClientMimeType(),
                        'size' => $file->getSize(),
                        'status' => 'pending',
                    ]);
                }
            }

            return $merchant->fresh(['documents']);
        });
    }

    private function makeCode(string $name): string
    {
        $base = Str::upper(Str::slug($name, '-'));
        $base = substr($base, 0, 20) ?: 'MERCHANT';
        $code = $base;
        $i = 1;

        while (Merchant::where('code', $code)->exists()) {
            $code = $base.'-'.$i++;
        }

        return $code;
    }
}
