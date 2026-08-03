<?php

namespace Modules\Merchant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Modules\Merchant\Events\MerchantApplicationChanged;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Services\MerchantSignupService;

class PublicMerchantSignupController extends Controller
{
    public function store(
        Request $request,
        MerchantSignupService $service
    ) {
        $data = $request->validate([
            'business_name' => [
                'required',
                'string',
                'max:180',
            ],

            'owner_name' => [
                'required',
                'string',
                'max:150',
            ],

            'contact_person' => [
                'nullable',
                'string',
                'max:150',
            ],

            'email' => [
                'required',
                'email',
                'unique:users,email',
                'unique:merchants,email',
            ],

            'phone' => [
                'required',
                'string',
                'max:30',
            ],

            'password' => [
                'required',
                'string',
                'min:6',
                'confirmed',
            ],
        ]);

        $result = $service->signup($data);

        $merchant = $result['merchant'] ?? null;

        if ($merchant instanceof Merchant) {
            $merchant = $merchant->fresh();

            event(
                new MerchantApplicationChanged(
                    merchantId: $merchant->id,
                    action: 'registered',
                    source: $merchant->application_source
                        ?: Merchant::SOURCE_PUBLIC_WEBSITE,
                    status: $merchant->status
                )
            );
        }

        return ApiResponse::success(
            [
                'merchant' => $merchant,
                'next_step' =>
                'login_to_complete_onboarding',
            ],
            'Merchant account created. Please login to complete onboarding.',
            201
        );
    }
}
