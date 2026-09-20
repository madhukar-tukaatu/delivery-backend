<?php

namespace Modules\Billing\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HamroPayRegistrationService
{
    private string $baseUrl;
    private string $apiKey;
    private string $appId;
    private string $authorization;
    private string $serviceId;
    private bool   $verifySsl;

    public function __construct()
    {
        $this->baseUrl       = rtrim(config('hamropay.registration_base_url', 'https://uatpay-api-rest.hamropatro.com'), '/');
        $this->apiKey        = config('hamropay.registration_api_key', '');
        $this->appId         = config('hamropay.registration_app_id', '');
        $this->authorization = config('hamropay.registration_authorization', '');
        $this->serviceId     = config('hamropay.registration_service_id', 'hp-merchant');
        $this->verifySsl     = (bool) config('hamropay.verify_ssl', true);
    }

    private function headers(): array
    {
        return [
            'Grpc-Metadata-api-key' => $this->apiKey,
            'Grpc-Metadata-app-id'  => $this->appId,
            'Authorization'         => $this->authorization,
            'Content-Type'          => 'application/json',
            'Accept'                => 'application/json',
        ];
    }

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        $req = Http::withHeaders($this->headers());
        if (!$this->verifySsl) {
            $req = $req->withoutVerifying();
        }
        return $req;
    }

    private function post(string $path, array $payload): array
    {
        if (empty($this->baseUrl)) {
            return ['error' => 'HamroPay registration is not configured on this server.', 'http_status' => 503];
        }

        $url = "{$this->baseUrl}{$path}";
        Log::info("HamroPay Registration [{$path}] request", ['payload' => $payload]);

        $response = $this->request()->post($url, $payload);

        Log::info("HamroPay Registration [{$path}] response", [
            'status' => $response->status(),
            'body'   => $response->body(),
        ]);

        if ($response->status() === 409) {
            return [
                'error'       => 'This phone number is already registered as a HamroPay merchant.',
                'http_status' => 409,
            ];
        }

        return $response->json() ?? [
            'error'       => 'HamroPay API returned HTTP ' . $response->status() . ' with no response body. This may be a rate limit or authentication issue.',
            'http_status' => $response->status(),
        ];
    }

    /**
     * Step 1 — Send OTP to phone number.
     */
    public function sendOtp(string $countryCode, string $phoneNumber, string $idempotentId): array
    {
        return $this->post('/api/v1/merchant-registration/send-otp', [
            'country_code'  => $countryCode,
            'phone_number'  => $phoneNumber,
            'service_id'    => $this->serviceId,
            'idempotent_id' => $idempotentId,
        ]);
    }

    /**
     * Step 2 — Validate OTP and receive verification_token.
     */
    public function validateOtp(
        string $otpId,
        string $otp,
        string $countryCode,
        string $phoneNumber,
        string $idempotentId
    ): array {
        return $this->post('/api/v1/merchant-registration/validate-otp', [
            'otp_id'        => $otpId,
            'otp'           => $otp,
            'country_code'  => $countryCode,
            'phone_number'  => $phoneNumber,
            'service_id'    => $this->serviceId,
            'idempotent_id' => $idempotentId,
        ]);
    }

    /**
     * Step 3 — Register merchant using verification_token from step 2.
     */
    public function registerMerchant(array $data): array
    {
        return $this->post('/api/v1/merchant-registration/register-merchant', array_merge($data, [
            'service_id' => $this->serviceId,
        ]));
    }

    /**
     * Step 4 — Submit business KYC.
     */
    public function updateBusinessKyc(array $data): array
    {
        return $this->post('/api/v1/business-kyc/update-business-kyc', array_merge($data, [
            'service_id' => $this->serviceId,
        ]));
    }
}
