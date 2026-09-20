<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Billing\Services\HamroPayRegistrationService;
use Modules\Merchant\Models\Merchant;

/**
 * Admin: register Express merchants on HamroPay (KYB API flow).
 */
class HamroPayMerchantController extends Controller
{
    public function __construct(private HamroPayRegistrationService $registration)
    {
    }

    public function status(Merchant $merchant)
    {
        return ApiResponse::success([
            'merchant_id' => $merchant->id,
            'hamropay_business_id' => $merchant->hamropay_business_id,
            'hamropay_merchant_id' => $merchant->hamropay_merchant_id,
            'hamropay_kyc_status' => $merchant->hamropay_kyc_status,
            'hamropay_registered_at' => $merchant->hamropay_registered_at,
            'hamropay_enabled_services' => $merchant->hamropay_enabled_services,
            'has_qr' => ! empty($merchant->hamropay_qr_payload),
            'configured' => filled(config('hamropay.registration_api_key')),
        ]);
    }

    public function sendOtp(Request $request, Merchant $merchant)
    {
        $data = $request->validate([
            'country_code' => ['nullable', 'string', 'max:8'],
            'phone_number' => ['required', 'string', 'max:32'],
        ]);

        $country = $data['country_code'] ?? '977';
        $phone = preg_replace('/\D+/', '', $data['phone_number']);
        $idempotent = (string) Str::uuid();

        $result = $this->registration->sendOtp($country, $phone, $idempotent);

        $merchant->forceFill(['hamropay_phone' => $phone])->save();

        return ApiResponse::success(array_merge(is_array($result) ? $result : [], [
            'idempotent_id' => $idempotent,
            'country_code' => $country,
            'phone_number' => $phone,
        ]), 'OTP requested.');
    }

    public function validateOtp(Request $request, Merchant $merchant)
    {
        $data = $request->validate([
            'otp_id' => ['required', 'string'],
            'otp' => ['required', 'string'],
            'country_code' => ['nullable', 'string', 'max:8'],
            'phone_number' => ['required', 'string', 'max:32'],
            'idempotent_id' => ['nullable', 'string'],
        ]);

        $result = $this->registration->validateOtp(
            $data['otp_id'],
            $data['otp'],
            $data['country_code'] ?? '977',
            preg_replace('/\D+/', '', $data['phone_number']),
            $data['idempotent_id'] ?? (string) Str::uuid(),
        );

        return ApiResponse::success($result, 'OTP validated.');
    }

    public function register(Request $request, Merchant $merchant)
    {
        $data = $request->validate([
            'verification_token' => ['required', 'string'],
            'country_code' => ['nullable', 'string', 'max:8'],
            'phone_number' => ['required', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:191'],
            'email' => ['nullable', 'email'],
            'category' => ['nullable', 'integer'],
            'idempotent_id' => ['nullable', 'string'],
        ]);

        $payload = [
            'country_code' => $data['country_code'] ?? '977',
            'phone_number' => preg_replace('/\D+/', '', $data['phone_number']),
            'name' => $data['name'] ?? ($merchant->business_name ?? $merchant->name ?? 'Merchant '.$merchant->id),
            'verification_token' => $data['verification_token'],
            'email' => $data['email'] ?? ($merchant->email ?? $merchant->contact_email ?? ('merchant'.$merchant->id.'@tukaatu.local')),
            'idempotent_id' => $data['idempotent_id'] ?? (string) Str::uuid(),
            'business_logo' => '',
            'category' => $data['category'] ?? 9, // CATEGORY_RETAIL_STORE
        ];

        $result = $this->registration->registerMerchant($payload);

        if (! empty($result['error'])) {
            return ApiResponse::error($result['error'], (int) ($result['http_status'] ?? 422));
        }

        $userId = data_get($result, 'user.id');
        $merchant->forceFill([
            'hamropay_business_id' => $userId,
            'hamropay_merchant_id' => $userId,
            'hamropay_qr_payload' => data_get($result, 'qr_payload'),
            'hamropay_phone' => $payload['phone_number'],
            'hamropay_registered_at' => now(),
            'hamropay_kyc_status' => 'registered',
        ])->save();

        return ApiResponse::success([
            'merchant' => $merchant->fresh(),
            'provider' => $result,
        ], 'Merchant registered on HamroPay.');
    }

    public function updateKyc(Request $request, Merchant $merchant)
    {
        $data = $request->validate([
            'document_id' => ['required', 'string'],
            'business_name' => ['nullable', 'string'],
            'authorized_person_name' => ['required', 'string'],
            'authorized_person_phone_number' => ['required', 'string'],
            'nature_of_company' => ['nullable', 'string'],
            'company_type' => ['nullable', 'integer'],
            'document_type' => ['nullable', 'integer'],
            'address' => ['nullable', 'array'],
            'idempotent_id' => ['nullable', 'string'],
        ]);

        $businessId = $merchant->hamropay_business_id;
        if (! $businessId) {
            return ApiResponse::error('Register the merchant on HamroPay first.', 422);
        }

        $payload = [
            'business_id' => $businessId,
            'business_type' => 1,
            'document_type' => $data['document_type'] ?? 0,
            'document_id' => $data['document_id'],
            'document_photo' => '',
            'registration_date' => (string) (now()->subYears(1)->getTimestampMs()),
            'kyc_status' => 1,
            'business_name' => $data['business_name'] ?? ($merchant->business_name ?? $merchant->name),
            'registration_certificate' => '',
            'business_logo' => '',
            'authorized_person_citizenship_front' => '',
            'authorized_person_citizenship_back' => '',
            'address' => $data['address'] ?? [
                'state' => 'Bagmati',
                'district' => 'Kathmandu',
                'zone' => '',
                'local_body' => '',
                'ward' => '1',
            ],
            'alternate_number' => '',
            'idempotent_id' => $data['idempotent_id'] ?? (string) Str::uuid(),
            'authorized_person_name' => $data['authorized_person_name'],
            'authorized_person_phone_number' => preg_replace('/\D+/', '', $data['authorized_person_phone_number']),
            'date_format' => 0,
            'nature_of_company' => $data['nature_of_company'] ?? 'Retail / delivery merchant',
            'company_type' => $data['company_type'] ?? 0,
            'business_trans_limit' => 0,
            'designation_of_authorized_person' => 'Owner',
            'declaration' => [
                'MISLEADING_INFORMATION' => true,
                'CASH_TRANSACTION_NOT_ALLOWED' => true,
                'NO_FRAUDULENT_ACTIVITY' => true,
                'NO_ILLEGAL_ACTIVITIES' => true,
                'POLITICALLY_EXPOSED_PERSON' => true,
            ],
            'enabled_service' => [
                'QR' => true,
                'BANK_OFFLOAD' => true,
                'HAMRO_PAY_IN' => true,
                'HAMRO_PAY_OUT' => true,
            ],
        ];

        $result = $this->registration->updateBusinessKyc($payload);

        if (! empty($result['error'])) {
            return ApiResponse::error($result['error'], (int) ($result['http_status'] ?? 422));
        }

        $merchant->forceFill([
            'hamropay_kyc_status' => 'submitted',
            'hamropay_enabled_services' => data_get($result, 'enabled_service', $payload['enabled_service']),
        ])->save();

        return ApiResponse::success([
            'merchant' => $merchant->fresh(),
            'provider' => $result,
        ], 'Business KYC submitted.');
    }
}
