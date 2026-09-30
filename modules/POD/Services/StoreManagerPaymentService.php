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
use Modules\Shipment\Models\Shipment;
use Throwable;

/**
 * Doorstep POD online payment via Store Manager payment sessions.
 *
 * Express asks Store Manager to create a shipment-specific QR/session so the
 * customer pays the merchant directly. HamroPay is NOT used here - HamroPay
 * covers HQ/settlement flows (branch commissions, KYB registration), not
 * rider doorstep POD collection.
 *
 * Cash POD remains a separate path on DeliveryWorkflowService::delivered.
 */
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

        $requestPayload = $this->buildRequestPayload($shipment, $merchant);
        $rawBody = json_encode(
            $requestPayload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        // Always call Store Manager for eligible shipments. Do NOT gate on
        // STORE_MANAGER_PAYMENT_ENABLED - that is Store Manager / ops concern.
        // Fail with a clear config error only when we are about to call and
        // base_url is missing. Auth/secret issues surface as SM HTTP errors.
        $baseUrl = trim((string) config('services.store_manager.payment.base_url'));
        if ($baseUrl === '') {
            throw ValidationException::withMessages([
                'payment' => ['Store Manager payment service URL is not configured (STORE_MANAGER_PAYMENT_BASE_URL).'],
            ]);
        }

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
                'payment' => ['The Store Manager payment service is unavailable. Check STORE_MANAGER_PAYMENT_BASE_URL and network access.'],
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

        if (! $response->successful()) {
            $providerBody = $response->json();
            Log::warning('Store Manager rejected payment session request.', [
                'shipment_id' => $shipment->id,
                'merchant_id' => $merchant->id,
                'status' => $response->status(),
                'body' => is_array($providerBody) ? $providerBody : $response->body(),
            ]);

            throw ValidationException::withMessages([
                'payment' => [$this->providerErrorMessage($response->status(), is_array($providerBody) ? $providerBody : null)],
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

    /**
     * Gateway response shape for Store Manager callers that expect QR fields
     * at the top level (POST /api/v1/gateway/payments/pod-qr).
     */
    public function createGatewayQr(Shipment $shipment, ?string $idempotencyKey = null): array
    {
        $session = $this->createForShipment($shipment, $idempotencyKey);

        return [
            'merchant_txn_id' => $session['merchant_txn_id'] ?? $session['payment_session_id'],
            'payment_session_id' => $session['payment_session_id'],
            'amount' => $session['amount'],
            'currency' => $session['currency'] ?? 'NPR',
            'status' => $session['status'],
            'qr_string' => $session['qr_string'] ?? data_get($session, 'payment.qr.payload'),
            'qr_image_url' => data_get($session, 'payment.qr.image_url'),
            'payment_url' => $session['payment_url'] ?? data_get($session, 'payment.checkout_url'),
            'payment_method' => $session['payment_method'] ?? 'online',
            'params' => [],
            'expires_at' => $session['expires_at'] ?? null,
            'shipment' => $session['shipment'] ?? [
                'tracking_number' => $shipment->tracking_number,
                'merchant_order_id' => $shipment->merchant_order_id,
            ],
        ];
    }

    public function currentForShipment(Shipment $shipment, bool $refresh = false): ?array
    {
        $session = PodPaymentSession::query()
            ->where('shipment_id', $shipment->id)
            ->latest('id')
            ->first();

        if (! $session) {
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

        if (! $session) {
            throw ValidationException::withMessages([
                'payment_session_id' => ['The payment session does not belong to this shipment.'],
            ]);
        }

        if ($session->status === 'pending') {
            $this->refreshSession($session);
            $session->refresh();
        }

        if (! $session->isPaid()) {
            throw ValidationException::withMessages([
                'payment_session_id' => [
                    'Store Manager has not confirmed this payment yet. Wait for the QR payment to complete, or poll GET payment-session?refresh=1.',
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
     * Verify a Store Manager payment_session_id for gateway / store flows.
     *
     * @return array{paid: bool, status: string, transaction_id: ?string, payment_session_id: string, amount: string}
     */
    public function verifyMerchantTxn(Merchant $merchant, string $merchantTxnId): array
    {
        $merchantTxnId = trim($merchantTxnId);
        if ($merchantTxnId === '') {
            throw ValidationException::withMessages([
                'merchant_txn_id' => ['merchant_txn_id (payment_session_id) is required.'],
            ]);
        }

        $session = PodPaymentSession::query()
            ->with(['merchant', 'shipment'])
            ->where('merchant_id', $merchant->id)
            ->where(function ($q) use ($merchantTxnId) {
                $q->where('payment_session_id', $merchantTxnId)
                    ->orWhere('provider_reference', $merchantTxnId);
            })
            ->latest('id')
            ->first();

        if (! $session) {
            throw ValidationException::withMessages([
                'merchant_txn_id' => ['Payment session was not found for this merchant.'],
            ]);
        }

        if ($session->isPaid()) {
            return $this->verifyResponse($session, true);
        }

        $this->refreshSession($session);
        $session->refresh();

        if (! $session->isPaid()) {
            return $this->verifyResponse($session, false);
        }

        $this->syncLocalPayment($session);

        return $this->verifyResponse($session->fresh(), true);
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

        if (! $merchant && $externalStoreId !== '') {
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

        // No STORE_MANAGER_PAYMENT_ENABLED gate. Skip remote poll only when
        // Express cannot form a Store Manager URL.
        $baseUrl = trim((string) config('services.store_manager.payment.base_url'));
        if ($baseUrl === '') {
            return;
        }

        $timestamp = (string) now()->timestamp;
        $secret = $this->secretFor($merchant);
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

        if (! $response->successful()) {
            $session->update([
                'last_error' => $this->providerErrorMessage($response->status(), $response->json()),
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
        if (! in_array($status, self::STATUSES, true)) {
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
        if (! in_array($status, self::STATUSES, true)) {
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
        if (! $session->isPaid()) {
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
        if (! $merchant) {
            throw ValidationException::withMessages([
                'payment' => ['The shipment merchant could not be found.'],
            ]);
        }

        if (trim((string) $merchant->external_store_id) === '') {
            throw ValidationException::withMessages([
                'payment' => [
                    'This merchant has no Store Manager external_store_id. Link the Express merchant to its Store Manager store before using online POD.',
                ],
            ]);
        }

        if (! in_array(strtolower((string) $shipment->payment_type), ['pod', 'cod', 'to_pay'], true)) {
            throw ValidationException::withMessages([
                'payment' => ['Online payment sessions are available only for POD / COD / To Pay shipments.'],
            ]);
        }

        if ($this->shipmentAmount($shipment) <= 0) {
            throw ValidationException::withMessages([
                'payment' => ['The shipment has no amount payable on delivery (total_collectable_amount / pod_amount is zero).'],
            ]);
        }

        $status = strtolower((string) $shipment->status);
        if (in_array($status, ['delivered', 'cancelled', 'canceled', 'returned', 'delivery_failed'], true)) {
            throw ValidationException::withMessages([
                'payment' => ['This shipment is no longer eligible for POD online payment.'],
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
        if (! ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > (int) config('services.store_manager.payment.webhook_tolerance', 300)) {
            abort(401, 'Invalid payment event timestamp.');
        }

        $secret = $this->secretFor($merchant);
        abort_unless($secret !== '', 401, 'Payment webhook secret is not configured.');

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        if ($signature === '' || ! hash_equals($expected, $signature)) {
            abort(401, 'Invalid payment event signature.');
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
        if ($value === null || $value === '' || ! is_numeric($value)) {
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
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function providerErrorMessage(int $status, ?array $body = null): string
    {
        $providerMessage = trim((string) (
            data_get($body, 'message')
            ?: data_get($body, 'error')
            ?: data_get($body, 'errors.payment.0')
            ?: data_get($body, 'errors.0')
            ?: ''
        ));

        $base = match (true) {
            $status === 401 || $status === 403 => 'Store Manager rejected the payment integration credentials.',
            $status === 404 => 'Store Manager could not find the store or order for this payment session.',
            $status === 409 => 'Store Manager rejected the payment request because the order or amount conflicts.',
            $status === 422 => 'Store Manager cannot create a payment session for this shipment (store payment account or order may be incomplete).',
            $status === 429 => 'Store Manager payment service is rate limited. Please try again shortly.',
            default => 'Store Manager payment service returned an error.',
        };

        if ($providerMessage !== '' && ! str_contains(strtolower($base), strtolower($providerMessage))) {
            return $base . ' Detail: ' . $providerMessage;
        }

        return $base;
    }

    private function formatSession(PodPaymentSession $session): array
    {
        $session->loadMissing(['merchant', 'shipment']);

        $qrPayload = is_string($session->qr_payload) ? trim($session->qr_payload) : '';
        $checkoutUrl = is_string($session->checkout_url) ? trim($session->checkout_url) : '';
        $qrString = $qrPayload !== '' ? $qrPayload : ($checkoutUrl !== '' ? $checkoutUrl : null);

        $shipment = $session->shipment;

        return [
            'payment_session_id' => $session->payment_session_id,
            'merchant_txn_id' => $session->payment_session_id,
            'status' => $session->status,
            'merchant' => [
                'external_store_id' => $session->external_store_id,
                'external_platform' => $session->external_platform,
                'name' => $session->merchant?->name,
            ],
            'shipment' => [
                'tracking_number' => $session->tracking_number,
                'merchant_order_id' => $session->merchant_order_id,
                'receiver' => [
                    'name' => $shipment?->receiver_name,
                    'phone' => $shipment?->receiver_phone,
                    'city' => $shipment?->receiver_city,
                    'area' => $shipment?->receiver_area,
                ],
            ],
            'amount' => number_format((float) $session->amount, 2, '.', ''),
            'currency' => $session->currency,
            'settlement_destination' => $session->settlement_destination,
            // Top-level aliases for staff FE / gateway consumers.
            'qr_string' => $qrString,
            'payment_url' => $checkoutUrl !== '' ? $checkoutUrl : null,
            'payment_method' => 'online',
            'payment' => [
                'channel' => 'qr',
                'purpose' => 'pod',
                'method' => 'online',
                'qr' => [
                    'format' => $session->qr_image_url ? 'image_url' : ($qrString ? 'payload' : null),
                    'image_url' => $session->qr_image_url,
                    'payload' => $qrString,
                ],
                'checkout_url' => $checkoutUrl !== '' ? $checkoutUrl : null,
            ],
            'provider_reference' => $session->provider_reference,
            'expires_at' => $session->expires_at?->toIso8601String(),
            'paid_at' => $session->paid_at?->toIso8601String(),
            'created_at' => $session->created_at?->toIso8601String(),
        ];
    }

    private function verifyResponse(PodPaymentSession $session, bool $paid): array
    {
        return [
            'paid' => $paid,
            'status' => $paid ? 'SUCCESS' : strtoupper((string) $session->status),
            'transaction_id' => $paid ? ($session->provider_reference ?: $session->payment_session_id) : null,
            'payment_session_id' => $session->payment_session_id,
            'merchant_txn_id' => $session->payment_session_id,
            'amount' => number_format((float) $session->amount, 2, '.', ''),
            'payment_method' => $paid ? 'online' : null,
            'payment_reference' => $paid ? ($session->provider_reference ?: $session->payment_session_id) : null,
        ];
    }
}
