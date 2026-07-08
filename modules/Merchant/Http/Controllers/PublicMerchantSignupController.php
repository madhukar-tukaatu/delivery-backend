<?php

namespace Modules\Merchant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Modules\Merchant\Services\MerchantSignupService;

class PublicMerchantSignupController extends Controller
{
    public function store(Request $request, MerchantSignupService $service)
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:180'],
            'owner_name' => ['required', 'string', 'max:150'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'email' => ['required', 'email', 'unique:users,email', 'unique:merchants,email'],
            'phone' => ['required', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ]);

        $result = $service->signup($data);

        return ApiResponse::success([
            'merchant' => $result['merchant'],
            'next_step' => 'login_to_complete_onboarding',
        ], 'Merchant account created. Please login to complete onboarding.', 201);
    }
}
