<?php

namespace Modules\Merchant\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Merchant\Models\Merchant;

class MerchantSignupService
{
    public function signup(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $merchant = Merchant::create([
                'name' => $data['business_name'],
                'code' => $this->generateMerchantCode($data['business_name']),
                'owner_name' => $data['owner_name'],
                'contact_person' => $data['contact_person'] ?? $data['owner_name'],
                'phone' => $data['phone'],
                'email' => $data['email'],
                'status' => 'onboarding',
                'verification_status' => 'profile_pending',
            ]);

            $user = User::create([
                'name' => $data['contact_person'] ?? $data['owner_name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'password' => Hash::make($data['password']),
                'role' => 'merchant',
                'merchant_id' => $merchant->id,
                'is_active' => true,
            ]);

            $user->syncRoles(['merchant']);

            return [
                'merchant' => $merchant->fresh(),
                'user' => $user->fresh(['roles']),
            ];
        });
    }

    private function generateMerchantCode(string $name): string
    {
        $prefix = Str::upper(Str::slug(Str::limit($name, 12, ''), '')) ?: 'MRC';
        $prefix = substr($prefix, 0, 8);
        $code = $prefix . '-' . random_int(1000, 9999);

        while (Merchant::where('code', $code)->exists()) {
            $code = $prefix . '-' . random_int(1000, 9999);
        }

        return $code;
    }
}
