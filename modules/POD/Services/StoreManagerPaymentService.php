<?php

namespace Modules\POD\Services;

use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Merchant\Models\Merchant;
use Modules\POD\Models\PodPaymentSession;
use Modules\POD\Models\PodRecord;
use Modules\Shipment\Models\Shipment;
use Throwable;

final class StoreManagerPaymentService
{
    private const STATUSES = [
        'pending',
        'paid',
        'failed',
        'expired',
        'cancelled',
        'refunded',
    ];

    public function createForShipment(Shipment $shipment, ?string $idempotencyKey = null): array
    {
        $shipment->loadMissing('merchant');
        $merchant = $shipment->merchant;
        $this->assertEligibleShipment($shipment, $merchant);

        $existing = PodPaymentSession::query()
            ->where('shipment_id', $shipment->id)
            ->whereIn('status', ['pending', 'paid'])
            ->latest('id')
            ->first();

        if ($existing) {
            if ($existing->status === 'pending' && $existing->expires_at?->isPast()) {
                $existing->update(['status' => 'expired']);
            } else {
                return $this->formatSession($existing);
            }
        }

        $idempotencyKey = trim((string) ($idempotencyKey ?: ''));
        if ($idempotencyKey === '') {
            $idempotencyKey = 'podpay_' . $shipment->id . '_' . Str::lower(Str::random(12));
        }

        $sameRequest = PodPaymentSession::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($sameRequest) {
            return $this->formatSession($sameRequest);
        }

        $this->ensureConfigured($merchant);

        $requestPayload = $this->buildRequestPayload($shipment, $merchant);
        $rawBody = json_encode(
            $requestPayload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $timestamp = (string) now()->timestamp;
        $secret = $this->secretFor($merchant);
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Tukaatu-Integration-Id' => (string) config('services.store_manager.payment.integration_id'),
            'X-Tukaatu-Timestamp' => $timestamp,
            'X-Tukaatu-Signature' => hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret),
            'Idempotency-Key' => $idempotencyKey,
        ];

        try {
            $response = Http::withHeaders($headers)
                ->withBody($rawBody, 'application/json')
                ->timeout((int) config('services.store_manager.payment.timeout', 20))
                ->post($this->endpoint((string) config('services.store_manager.payment.create_path')));
        } catch (ConnectionException $exception) {
            Log::warning('Store Manager payment session connection failed.', [
                'shipment_id' => $shipment->id,
                'merchant_id' => $merchant->id,
                'error' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'payment' => ['The Store Manager payment service is unavailable.'],
            ]);
        } catch (Throwable $exception) {
            Log::error('Store Manager payment session request failed.', [
                'shipment_id' => $shipment->id,
                'merchant_id' => $merchant->id,
                'error' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'payment' => ['The online payment session could not be created.'],
            ]);
        }

        if (!$response->successful()) {
            Log::warning('Store Manager rejected payment session request.', [
                'shipment_id' => $shipment->id,
                'merchant_id' => $merchant->id,
                'status' => $response->status(),
            ]);

            throw ValidationException::withMessages([
                'payment' => [$this->providerErrorMessage($response->status())],
            ]);
        }

        $providerPayload = $response->json();
        $providerData = data_get($providerPayload, 'data', $providerPayload);
        $session = $this->persistProviderSession(
            shipment: $shipment,
            merchant: $merchant,
            idempotencyKey: $idempotencyKey,
            providerData: is_array($providerData) ? $providerData : [],
            rawPayload: is_array($providerPayload) ? $providerPayload : [],
        );

        if ($session->isPaid()) {
            $this->syncLocalPayment($session);
        }

        return $this->formatSession($session->fresh(['merchant', 'shipment']));
    }

    public function currentForShipment(Shipment $shipment, bool $refresh = false): ?array
    {
        $session = PodPaymentSession::query()
            ->where('shipment_id', $shipment->id)
            ->latest('id')
            ->first();

        if (!$session) {
            return null;
        }

        if ($session->status === 'pending' && $session->expires_at?->isPast()) {
            $session->update(['status' => 'expired']);
        } elseif ($refresh && $session->status === 'pending') {
            $this->refreshSession($session);
        }

        return $this->formatSession($session->fresh(['merchant', 'shipment']));
    }

    public function assertPaid(Shipment $shipment, string $paymentSessionId): PodPaymentSession
    {
        $session = PodPaymentSession::query()
            ->where('payment_session_id', $paymentSessionId)
            ->where('shipment_id', $shipment->id)
            ->where('merchant_id', $shipment->merchant_id)
            ->first();

        if (!$session) {
            throw ValidationException::withMessages([
                'payment_session_id' => ['The payment session does not belong to this shipment.'],
            ]);
        }

        if ($session->status === 'pending') {
            $this->refreshSession($session);
            $session->refresh();
        }

        if (!$session->isPaid()) {
            throw ValidationException::withMessages([
                'payment_session_id' => [
                    'The Store Manager has not confirmed this payment yet.',
                ],
            ]);
        }

        $this->assertSessionAmount($shipment, $session);

        if ($session->settlement_destination !== 'merchant') {
            throw ValidationException::withMessages([
                'payment_session_id' => [
                    'This payment is not confirmed as a direct merchant payment.',
                ],
            ]);
        }

        $this->syncLocalPayment($session);

        return $session->fresh(['merchant', 'shipment']);
    }

    /**
     * Process a signed Store Manager payment event.
     *
     * The event never marks the shipment delivered. It only records a
     * verified payment so the assigned rider can complete delivery.
     */
    public function handleWebhook(
        string $rawBody,
        array $payload,
        string $timestamp,
        string $signature,
        string $eventId,
    ): array {
        $paymentSessionId = trim((string) ($payload['payment_session_id'] ?? ''));
        $session = $paymentSessionId !== ''
            ? PodPaymentSession::query()->with(['merchant', 'shipment'])
                ->where('payment_session_id', $paymentSessionId)
                ->first()
            : null;

        $externalStoreId = trim((string) data_get($payload, 'merchant.external_store_id'));
        $merchant = $session?->merchant;

        if (!$merchant && $externalStoreId !== '') {
            $merchant = Merchant::query()
                ->where('external_store_id', $externalStoreId)
                ->first();
        }

        abort_unless($merchant, 404, 'Payment merchant was not found.');
        $this->verifyWebhookSignature($merchant, $rawBody, $timestamp, $signature);
        abort_unless($session, 404, 'Payment session was not found.');

        if ($session->last_event_id === $eventId) {
            return $this->formatSession($session);
        }

        $this->reconcileWebhook($session, $payload, $externalStoreId);
        $session->update([
            'last_event_id' => $eventId,
            'response_payload' => $payload,
        ]);

        if ($session->isPaid()) {
            $this->syncLocalPayment($session);
        }

        return $this->formatSession($session->fresh(['merchant', 'shipment']));
    }

    private function refreshSession(PodPaymentSession $session): void
    {
        $merchant = $session->merchant ?: Merchant::query()->findOrFail($session->merchant_id);
        $this->ensureConfigured($merchant);

        $timestamp = (string) now()->timestamp;
        $secret = $this->secretFor($merchant);
        $rawBody = '';
        $headers = [
            'Accept' => 'application/json',
            'X-Tukaatu-Integration-Id' => (string) config('services.store_manager.payment.integration_id'),
            'X-Tukaatu-Timestamp' => $timestamp,
            'X-Tukaatu-Signature' => hash_hmac('sha256', $timestamp . '.', $secret),
        ];

        $path = str_replace(
            '{payment_session_id}',
            rawurlencode($session->payment_session_id),
            (string) config('services.store_manager.payment.status_path')
        );

        try {
            $response = Http::withHeaders($headers)
                ->timeout((int) config('services.store_manager.payment.timeout', 20))
                ->get($this->endpoint($path));
        } catch (Throwable $exception) {
            Log::warning('Store Manager payment status request failed.', [
                'payment_session_id' => $session->payment_session_id,
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        $session->update(['last_polled_at' => now()]);

        if (!$response->successful()) {
            $session->update([
                'last_error' => $this->providerErrorMessage($response->status()),
            ]);

            return;
        }

        $payload = $response->json();
        $data = data_get($payload, 'data', $payload);

        if (is_array($data)) {
            $this->applyProviderData($session, $data, is_array($payload) ? $payload : []);

            if ($session->fresh()->isPaid()) {
                $this->syncLocalPayment($session->fresh());
            }
        }
    }

    private function persistProviderSession(
        Shipment $shipment,
        Merchant $merchant,
        string $idempotencyKey,
        array $providerData,
        array $rawPayload,
    ): PodPaymentSession {
        $providerSessionId = trim((string) data_get($providerData, 'payment_session_id'));
        if ($providerSessionId === '') {
            throw ValidationException::withMessages([
                'payment' => ['Store Manager returned no payment session identifier.'],
            ]);
        }

        $status = strtolower((string) data_get($providerData, 'status', 'pending'));
        if (!in_array($status, self::STATUSES, true)) {
            throw ValidationException::withMessages([
                'payment' => ['Store Manager returned an unsupported payment status.'],
            ]);
        }

        $amount = $this->decimalAmount(data_get($providerData, 'amount'));
        $expectedAmount = $this->shipmentAmount($shipment);
        if ($amount === null || $this->cents($amount) !== $this->cents($expectedAmount)) {
            throw ValidationException::withMessages([
                'payment' => ['Store Manager returned a missing or different payment amount for the shipment.'],
            ]);
        }

        $currency = strtoupper(trim((string) data_get($providerData, 'currency')));
        if ($currency !== 'NPR') {
            throw ValidationException::withMessages([
                'payment' => ['Store Manager returned an unsupported or mismatched payment currency.'],
            ]);
        }

        $destination = strtolower((string) data_get($providerData, 'settlement_destination'));
        if ($destination !== 'merchant') {
            throw ValidationException::withMessages([
                'payment' => ['Store Manager did not confirm direct settlement to the merchant.'],
            ]);
        }

        $qr = data_get($providerData, 'payment.qr', data_get($providerData, 'qr', []));
        $session = PodPaymentSession::updateOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'merchant_id' => $merchant->id,
                'shipment_id' => $shipment->id,
                'payment_session_id' => $providerSessionId,
                'external_store_id' => $merchant->external_store_id,
                'external_platform' => $merchant->external_platform,
                'merchant_order_id' => $shipment->merchant_order_id,
                'tracking_number' => $shipment->tracking_number,
                'amount' => $expectedAmount,
                'currency' => strtoupper((string) data_get($providerData, 'currency', 'NPR')),
                'status' => $status,
                'settlement_destination' => $destination,
                'provider_reference' => data_get($providerData, 'provider_reference'),
                'qr_image_url' => data_get($qr, 'image_url'),
                'qr_payload' => data_get($qr, 'payload'),
                'checkout_url' => data_get($providerData, 'payment.checkout_url', data_get($providerData, 'checkout_url')),
                'expires_at' => $this->dateValue(data_get($providerData, 'expires_at')),
                'paid_at' => $this->dateValue(data_get($providerData, 'paid_at')),
                'response_payload' => $rawPayload,
                'last_error' => null,
            ]
        );

        return $session;
    }

    private function applyProviderData(PodPaymentSession $session, array $data, array $rawPayload = []): void
    {
        $status = strtolower((string) data_get($data, 'status', $session->status));
        if (!in_array($status, self::STATUSES, true)) {
            return;
        }

        if (array_key_exists('amount', $data)) {
            $amount = $this->decimalAmount(data_get($data, 'amount'));
            if ($amount === null || $this->cents($amount) !== $this->cents((float) $session->amount)) {
                $session->update(['last_error' => 'Store Manager returned a payment amount different from the session.']);
                return;
            }
        }

        if (
            array_key_exists('currency', $data)
            && strtoupper(trim((string) data_get($data, 'currency'))) !== strtoupper((string) $session->currency)
        ) {
            $session->update(['last_error' => 'Store Manager returned a payment currency different from the session.']);
            return;
        }

        if (
            array_key_exists('settlement_destination', $data)
            && strtolower((string) data_get($data, 'settlement_destination')) !== 'merchant'
        ) {
            $session->update(['last_error' => 'Store Manager did not confirm direct merchant settlement.']);
            return;
        }

        $updates = [
            'status' => $status,
            'provider_reference' => data_get($data, 'provider_reference', $session->provider_reference),
            'expires_at' => $this->dateValue(data_get($data, 'expires_at')) ?: $session->expires_at,
            'paid_at' => $this->dateValue(data_get($data, 'paid_at')) ?: $session->paid_at,
            'response_payload' => $rawPayload ?: $session->response_payload,
            'last_error' => null,
        ];

        if ($status === 'failed') {
            $updates['failed_at'] = now();
        }

        if ($status === 'cancelled' || $status === 'refunded') {
            $updates['cancelled_at'] = now();
        }

        $session->update($updates);
    }

    private function reconcileWebhook(PodPaymentSession $session, array $payload, string $externalStoreId): void
    {
        $shipment = $session->shipment ?: Shipment::query()->findOrFail($session->shipment_id);
        $payment = data_get($payload, 'payment', []);
        $status = strtolower((string) data_get($payment, 'status', data_get($payload, 'status')));

        abort_unless($externalStoreId === (string) $session->external_store_id, 422, 'Payment merchant does not match the session.');
        abort_unless(
            (string) data_get($payload, 'shipment.tracking_number') === (string) $session->tracking_number,
            422,
            'Payment shipment does not match the session.'
        );

        $amount = $this->decimalAmount(data_get($payment, 'amount'));
        abort_unless($amount !== null && $this->cents($amount) === $this->cents((float) $session->amount), 422, 'Payment amount does not match the session.');
        abort_unless(strtoupper((string) data_get($payment, 'currency')) === strtoupper((string) $session->currency), 422, 'Payment currency does not match the session.');
        abort_unless(strtolower((string) data_get($payment, 'settlement_destination')) === 'merchant', 422, 'Payment is not settled to the merchant.');
        abort_unless(in_array($status, self::STATUSES, true), 422, 'Unsupported payment status.');

        $this->applyProviderData($session, [
            'status' => $status,
            'provider_reference' => data_get($payment, 'provider_reference'),
            'paid_at' => data_get($payment, 'paid_at'),
        ], $payload);
    }

    private function syncLocalPayment(PodPaymentSession $session): void
    {
        if (!$session->isPaid()) {
            return;
        }

        $shipment = $session->shipment ?: Shipment::query()->findOrFail($session->shipment_id);
        app(PODWorkflowService::class)->markPaidDirectToMerchant(
            $shipment,
            null,
            (float) $session->amount,
            'online',
            $session->provider_reference,
            $session->payment_session_id,
        );
    }

    private function assertEligibleShipment(Shipment $shipment, ?Merchant $merchant): void
    {
        if (!$merchant) {
            throw ValidationException::withMessages([
                'payment' => ['The shipment merchant could not be found.'],
            ]);
        }

        if (!in_array(strtolower((string) $shipment->payment_type), ['pod', 'cod', 'to_pay'], true)) {
            throw ValidationException::withMessages([
                'payment' => ['Online payment sessions are available only for POD shipments.'],
            ]);
        }

        if ($this->shipmentAmount($shipment) <= 0) {
            throw ValidationException::withMessages([
                'payment' => ['The shipment has no amount payable on delivery.'],
            ]);
        }
    }

    private function assertSessionAmount(Shipment $shipment, PodPaymentSession $session): void
    {
        if ($this->cents((float) $session->amount) !== $this->cents($this->shipmentAmount($shipment))) {
            throw ValidationException::withMessages([
                'payment_session_id' => ['The payment session amount does not match the shipment amount.'],
            ]);
        }
    }

    private function buildRequestPayload(Shipment $shipment, Merchant $merchant): array
    {
        return [
            'request_id' => 'req_' . Str::lower(Str::random(24)),
            'merchant' => [
                'external_store_id' => $merchant->external_store_id,
                'external_platform' => $merchant->external_platform,
                'merchant_reference' => $merchant->code,
            ],
            'shipment' => [
                'tracking_number' => $shipment->tracking_number,
                'merchant_order_id' => $shipment->merchant_order_id,
                'payment_type' => strtolower((string) $shipment->payment_type),
                'pod_amount' => number_format((float) $shipment->pod_amount, 2, '.', ''),
                'delivery_charge' => number_format((float) $shipment->delivery_charge, 2, '.', ''),
                'delivery_charge_paid_by' => $shipment->delivery_charge_paid_by,
                'total_collectable_amount' => number_format($this->shipmentAmount($shipment), 2, '.', ''),
                'currency' => 'NPR',
                'receiver' => [
                    'name' => $shipment->receiver_name,
                    'phone' => $shipment->receiver_phone,
                    'city' => $shipment->receiver_city,
                    'area' => $shipment->receiver_area,
                ],
            ],
            'payment' => [
                'channel' => 'qr',
                'purpose' => 'pod',
                'requested_at' => now()->toIso8601String(),
            ],
            'callback' => [
                'url' => rtrim((string) config('app.url'), '/') . '/api/v1/integrations/store-manager/payment-events',
                'events' => ['pod.payment.paid', 'pod.payment.failed', 'pod.payment.expired'],
            ],
        ];
    }

    private function verifyWebhookSignature(Merchant $merchant, string $rawBody, string $timestamp, string $signature): void
    {
        if (!ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > (int) config('services.store_manager.payment.webhook_tolerance', 300)) {
            abort(401, 'Invalid payment event timestamp.');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->secretFor($merchant));
        if ($signature === '' || !hash_equals($expected, $signature)) {
            abort(401, 'Invalid payment event signature.');
        }
    }

    private function ensureConfigured(Merchant $merchant): void
    {
        if (!config('services.store_manager.payment.enabled')) {
            throw ValidationException::withMessages([
                'payment' => ['Store Manager online payment is not enabled.'],
            ]);
        }

        if (trim((string) config('services.store_manager.payment.base_url')) === '') {
            throw ValidationException::withMessages([
                'payment' => ['Store Manager payment service URL is not configured.'],
            ]);
        }

        if ($this->secretFor($merchant) === '') {
            throw ValidationException::withMessages([
                'payment' => ['Store Manager payment credentials are not configured for this merchant.'],
            ]);
        }
    }

    private function secretFor(Merchant $merchant): string
    {
        return trim((string) ($merchant->integration_callback_secret ?: config('services.store_manager.payment.shared_secret')));
    }

    private function endpoint(string $path): string
    {
        return rtrim((string) config('services.store_manager.payment.base_url'), '/') . '/' . ltrim($path, '/');
    }

    private function shipmentAmount(Shipment $shipment): float
    {
        return round((float) ($shipment->total_collectable_amount ?: $shipment->total_collectable ?: $shipment->pod_amount), 2);
    }

    private function decimalAmount(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    private function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function dateValue(mixed $value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function providerErrorMessage(int $status): string
    {
        return match (true) {
            $status === 401 || $status === 403 => 'Store Manager rejected the payment integration credentials.',
            $status === 409 => 'Store Manager rejected the payment request because the order or amount conflicts.',
            $status === 422 => 'Store Manager cannot create a payment session for this shipment.',
            $status === 429 => 'Store Manager payment service is rate limited. Please try again shortly.',
            default => 'Store Manager payment service returned an error.',
        };
    }

    private function formatSession(PodPaymentSession $session): array
    {
        $session->loadMissing(['merchant', 'shipment']);

        return [
            'payment_session_id' => $session->payment_session_id,
            'status' => $session->status,
            'merchant' => [
                'external_store_id' => $session->external_store_id,
                'external_platform' => $session->external_platform,
                'name' => $session->merchant?->name,
            ],
            'shipment' => [
                'tracking_number' => $session->tracking_number,
                'merchant_order_id' => $session->merchant_order_id,
            ],
            'amount' => number_format((float) $session->amount, 2, '.', ''),
            'currency' => $session->currency,
            'settlement_destination' => $session->settlement_destination,
            'payment' => [
                'channel' => 'qr',
                'purpose' => 'pod',
                'qr' => [
                    'format' => $session->qr_image_url ? 'image_url' : ($session->qr_payload ? 'payload' : null),
                    'image_url' => $session->qr_image_url,
                    'payload' => $session->qr_payload,
                ],
                'checkout_url' => $session->checkout_url,
            ],
            'provider_reference' => $session->provider_reference,
            'expires_at' => $session->expires_at?->toIso8601String(),
            'paid_at' => $session->paid_at?->toIso8601String(),
            'created_at' => $session->created_at?->toIso8601String(),
        ];
    }
}
