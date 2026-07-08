<?php

namespace Modules\Merchant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Modules\Merchant\Services\MerchantRegistrationService;

class PublicMerchantRegistrationController extends Controller
{
    public function store(Request $request, MerchantRegistrationService $service)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'owner_name' => ['required', 'string', 'max:150'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['required', 'email', 'unique:merchants,email', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'website_url' => ['nullable', 'url', 'max:255'],
            'business_type' => ['nullable', 'string', 'max:100'],
            'pan_vat_number' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'pickup_address' => ['required', 'string', 'max:500'],
            'pickup_city' => ['nullable', 'string', 'max:100'],
            'pickup_area' => ['nullable', 'string', 'max:100'],
            'pickup_lat' => ['required', 'numeric'],
            'pickup_lng' => ['required', 'numeric'],
            'bank_name' => ['nullable', 'string', 'max:150'],
            'bank_account_name' => ['nullable', 'string', 'max:150'],
            'bank_account_number' => ['nullable', 'string', 'max:100'],
            'documents.business_registration' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'documents.pan_vat' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'documents.owner_id' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'documents.bank_proof' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        $merchant = $service->register($data, $request->file('documents', []));

        return ApiResponse::success([
            'merchant' => $merchant,
            'message' => 'Registration submitted. Your account will be activated after document verification.',
        ], 'Merchant registration submitted.', 201);
    }
}
