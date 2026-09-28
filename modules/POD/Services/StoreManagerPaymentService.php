<?php

namespace Modules\POD\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Services\PaymentGatewayAccountService;
use Modules\Billing\Services\HamroPayService;
use Modules\Merchant\Models\Merchant;
use Modules\POD\Models\PodPaymentSession;
use Modules\Shipment\Models\Shipment;
use Throwable;

/**
 * POD online payment via HamroPay.
 *
 * Express fetches a checkout session from HamroPay (createSession) and routes
 * funds to the store's HamroPay sub-merchant. Store Manager never invents QR;
 * it calls the Express gateway which returns qr_string / payment_url / params.
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

    private const CACHE_TTL_MINUTES = 30;

    public function __construct(
        private readonly PaymentGatewayAccountService $gatewayAccounts,
    ) {
    }

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

        return $this->createHamroPaySession($shipment, $merchant, $idempotencyKey);
    }

    /**
     * Gateway response shape for Store Manager POD QR.
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
            'payment_url' => $session['payment_url'] ?? data_get($session, 'payment.checkout_url'),
            'params' => $session['params'] ?? [],
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
                    'HamroPay has not confirmed this payment yet.',
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
     * Verify a HamroPay merchantTxnId for gateway / store flows.
     *
     * @return array{paid: bool, status: string, transaction_id: ?string, payment_session_id: string, amount: string}
     */
    public function verifyMerchantTxn(Merchant $merchant, string $merchantTxnId): array
    {
        $merchantTxnId = trim($merchantTxnId);
        if ($merchantTxnId === '') {
            throw ValidationException::withMessages([
                'merchant_txn_id' => ['merchant_txn_id is required.'],
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

        $intent = Cache::get($this->cacheKey($merchantTxnId));

        if (! $session && is_array($intent) && (int) ($intent['merchant_id'] ?? 0) === (int) $merchant->id) {
            $session = PodPaymentSession::query()
                ->with(['merchant', 'shipment'])
                ->where('id', (int) ($intent['session_db_id'] ?? 0))
                ->first();
        }

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
     * Kept for webhook route compatibility; HamroPay verification is poll-based.
     */
    public function handleWebhook(
        string $rawBody,
        array $payload,
        string $timestamp,
        string $signature,
        string $eventId,
    ): array {
        $merchantTxnId = trim((string) (
            $payload['merchant_txn_id']
            ?? $payload['merchantTxnId']
            ?? $payload['payment_session_id']
            ?? ''
        ));

        abort_unless($merchantTxnId !== '', 422, 'merchant_txn_id is required.');

        $session = PodPaymentSession::query()
            ->with(['merchant', 'shipment'])
            ->where('payment_session_id', $merchantTxnId)
            ->first();

        abort_unless($session?->merchant, 404, 'Payment session was not found.');

        $this->verifyWebhookSignature($session->merchant, $rawBody, $timestamp, $signature);

        if ($session->last_event_id === $eventId && $session->isPaid()) {
            return $this->formatSession($session);
        }

        $this->refreshSession($session);
        $session->refresh();
        $session->update([
            'last_event_id' => $eventId,
            'response_payload' => array_merge((array) $session->response_payload, ['webhook' => $payload]),
        ]);

        if ($session->isPaid()) {
            $this->syncLocalPayment($session);
        }

        return $this->formatSession($session->fresh(['merchant', 'shipment']));
    }

    private function verifyWebhookSignature(Merchant $merchant, string $rawBody, string $timestamp, string $signature): void
    {
        if (! ctype_digit($timestamp) || abs(now()->timestamp - (int) $timestamp) > (int) config('services.store_manager.payment.webhook_tolerance', 300)) {
            abort(401, 'Invalid payment event timestamp.');
        }

        $secret = trim((string) ($merchant->integration_callback_secret ?: config('services.store_manager.payment.shared_secret')));
        abort_unless($secret !== '', 401, 'Payment webhook secret is not configured.');

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        if ($signature === '' || ! hash_equals($expected, $signature)) {
            abort(401, 'Invalid payment event signature.');
        }
    }

    private function createHamroPaySession(Shipment $shipment, Merchant $merchant, string $idempotencyKey): array
    {
        $subMerchantId = trim((string) ($merchant->hamropay_merchant_id ?: $merchant->hamropay_business_id));
        if ($subMerchantId === '') {
            throw ValidationException::withMessages([
                'payment' => ['Store HamroPay sub-merchant is not registered. Complete HamroPay KYB first.'],
            ]);
        }

        $amount = $this->shipmentAmount($shipment);
        $paisa = (int) round($amount * 100);
        if ($paisa < 1) {
            throw ValidationException::withMessages([
                'payment' => ['The shipment has no amount payable on delivery.'],
            ]);
        }

        $client = $this->hamroPayClient($shipment);
        $platformMerchantId = $client->platformMerchantId() ?: (string) config('hamropay.merchant_id');
        if ($platformMerchantId === '') {
            throw ValidationException::withMessages([
                'payment' => ['HamroPay platform merchant is not configured.'],
            ]);
        }

        $merchantTxnId = 'POD' . $shipment->id . Str::upper(Str::random(10));
        if (strlen($merchantTxnId) > 40) {
            $merchantTxnId = Str::limit($merchantTxnId, 40, '');
        }

        $successUrl = rtrim((string) config('app.url'), '/') . '/api/v1/integrations/store-manager/payment-events';
        $failureUrl = $successUrl;

        try {
            $sessionData = $client->createSession([
                'merchantTxnId' => $merchantTxnId,
                'transactionAmount' => $paisa,
                'failedRedirectionUrl' => $failureUrl,
                'successRedirectionUrl' => $successUrl . '?merchant_txn_id=' . urlencode($merchantTxnId),
                'productList' => [],
                'metadata' => [
                    'source' => 'express_pod',
                    'shipment_id' => (string) $shipment->id,
                    'tracking_number' => (string) $shipment->tracking_number,
                    'merchant_order_id' => (string) $shipment->merchant_order_id,
                ],
                'remarks' => 'POD ' . $shipment->tracking_number,
            ], $platformMerchantId, $subMerchantId);
        } catch (Throwable $e) {
            Log::error('HamroPay POD createSession exception', [
                'shipment_id' => $shipment->id,
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'payment' => ['Unable to create HamroPay payment session.'],
            ]);
        }

        $sessionId = (string) (
            data_get($sessionData, 'sessionId')
            ?? data_get($sessionData, 'data.sessionId')
            ?? data_get($sessionData, 'session_id')
            ?? ''
        );

        if ($sessionId === '') {
            Log::warning('HamroPay POD createSession missing sessionId', [
                'shipment_id' => $shipment->id,
                'response' => $sessionData,
            ]);

            throw ValidationException::withMessages([
                'payment' => [
                    (string) (data_get($sessionData, 'message') ?: 'HamroPay did not return a session id.'),
                ],
            ]);
        }

        $checkoutMerchantId = (string) (
            data_get($sessionData, 'merchantId')
            ?? data_get($sessionData, 'data.merchantId')
            ?? $platformMerchantId
        );

        $params = $client->buildCheckoutParams(
            $sessionId,
            $merchantTxnId,
            $paisa,
            'POD ' . $shipment->tracking_number,
            $checkoutMerchantId,
            $successUrl . '?merchant_txn_id=' . urlencode($merchantTxnId),
            $failureUrl,
            $subMerchantId,
        );

        $paymentUrl = rtrim($client->getGatewayUrl(), '/') . '/api/checkout';
        $query = http_build_query($params);
        if ($query !== '') {
            $paymentUrl .= (str_contains($paymentUrl, '?') ? '&' : '?') . $query;
        }
        $qrString = $this->extractQrString($sessionData, $paymentUrl, $params, $merchant);

        $expiresAt = now()->addMinutes(self::CACHE_TTL_MINUTES);

        $session = PodPaymentSession::updateOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'merchant_id' => $merchant->id,
                'shipment_id' => $shipment->id,
                'payment_session_id' => $merchantTxnId,
                'external_store_id' => $merchant->external_store_id,
                'external_platform' => $merchant->external_platform,
                'merchant_order_id' => $shipment->merchant_order_id,
                'tracking_number' => $shipment->tracking_number,
                'amount' => $amount,
                'currency' => 'NPR',
                'status' => 'pending',
                'settlement_destination' => 'merchant',
                'provider_reference' => $sessionId,
                'qr_image_url' => null,
                'qr_payload' => $qrString,
                'checkout_url' => $paymentUrl,
                'expires_at' => $expiresAt,
                'paid_at' => null,
                'response_payload' => [
                    'hamropay_session' => $sessionData,
                    'params' => $params,
                    'payment_url' => $paymentUrl,
                    'sub_merchant_id' => $subMerchantId,
                    'platform_merchant_id' => $checkoutMerchantId,
                ],
                'last_error' => null,
            ]
        );

        Cache::put($this->cacheKey($merchantTxnId), [
            'merchant_id' => $merchant->id,
            'shipment_id' => $shipment->id,
            'session_db_id' => $session->id,
            'amount' => $amount,
            'paisa' => $paisa,
            'sub_merchant_id' => $subMerchantId,
            'platform_merchant_id' => $checkoutMerchantId,
            'hamropay_session_id' => $sessionId,
            'params' => $params,
            'payment_url' => $paymentUrl,
            'qr_string' => $qrString,
        ], $expiresAt);

        return $this->formatSession($session->fresh(['merchant', 'shipment']));
    }

    private function refreshSession(PodPaymentSession $session): void
    {
        if ($session->status !== 'pending') {
            return;
        }

        if ($session->expires_at?->isPast()) {
            $session->update(['status' => 'expired']);

            return;
        }

        $shipment = $session->shipment ?: Shipment::query()->find($session->shipment_id);
        $client = $this->hamroPayClient($shipment);
        $intent = Cache::get($this->cacheKey($session->payment_session_id));
        $platformMerchantId = is_array($intent)
            ? (string) ($intent['platform_merchant_id'] ?? '')
            : (string) data_get($session->response_payload, 'platform_merchant_id');

        try {
            $result = $client->getTransaction(
                $session->payment_session_id,
                $platformMerchantId !== '' ? $platformMerchantId : null,
            );
        } catch (Throwable $e) {
            Log::warning('HamroPay POD getTransaction failed', [
                'payment_session_id' => $session->payment_session_id,
                'error' => $e->getMessage(),
            ]);
            $session->update([
                'last_polled_at' => now(),
                'last_error' => 'HamroPay status check failed.',
            ]);

            return;
        }

        $session->update(['last_polled_at' => now()]);

        $status = strtoupper((string) (
            data_get($result, 'status')
            ?? data_get($result, 'transactionStatus')
            ?? data_get($result, 'data.status')
            ?? ''
        ));

        $paid = in_array($status, ['SUCCESS', 'COMPLETE', 'COMPLETED', 'PAID'], true)
            || (bool) data_get($result, 'success');

        if (! $paid) {
            if (in_array($status, ['FAILED', 'FAILURE', 'CANCELLED', 'CANCELED', 'EXPIRED'], true)) {
                $session->update([
                    'status' => $status === 'EXPIRED' ? 'expired' : (str_starts_with($status, 'CANCEL') ? 'cancelled' : 'failed'),
                    'failed_at' => now(),
                    'response_payload' => array_merge((array) $session->response_payload, ['verify' => $result]),
                    'last_error' => data_get($result, 'message'),
                ]);
            }

            return;
        }

        $providerRef = (string) (
            data_get($result, 'transactionId')
            ?? data_get($result, 'transaction_id')
            ?? data_get($result, 'data.transactionId')
            ?? $session->payment_session_id
        );

        $session->update([
            'status' => 'paid',
            'settlement_destination' => 'merchant',
            'provider_reference' => $providerRef,
            'paid_at' => now(),
            'response_payload' => array_merge((array) $session->response_payload, ['verify' => $result]),
            'last_error' => null,
        ]);

        Cache::forget($this->cacheKey($session->payment_session_id));
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

        if (! in_array(strtolower((string) $shipment->payment_type), ['pod', 'cod', 'to_pay'], true)) {
            throw ValidationException::withMessages([
                'payment' => ['Online payment sessions are available only for POD shipments.'],
            ]);
        }

        if ($this->shipmentAmount($shipment) <= 0) {
            throw ValidationException::withMessages([
                'payment' => ['The shipment has no amount payable on delivery.'],
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

    private function hamroPayClient(?Shipment $shipment): HamroPayService
    {
        $branchId = $shipment
            ? ($shipment->destination_branch_id ?? $shipment->current_branch_id ?? $shipment->origin_branch_id)
            : null;

        $account = $this->gatewayAccounts->resolvePayerAccount($branchId ? (int) $branchId : null, 'hamropay');

        return $this->gatewayAccounts->hamroPayClientFromAccount($account);
    }

    private function extractQrString(array $sessionData, string $paymentUrl, array $params, Merchant $merchant): ?string
    {
        $candidates = [
            data_get($sessionData, 'qrString'),
            data_get($sessionData, 'qr_string'),
            data_get($sessionData, 'qrPayload'),
            data_get($sessionData, 'qr_payload'),
            data_get($sessionData, 'data.qrString'),
            data_get($sessionData, 'data.qr_string'),
            data_get($sessionData, 'data.qrPayload'),
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        if ($paymentUrl !== '') {
            // payment_url may already include checkout params from createHamroPaySession.
            if (! str_contains($paymentUrl, '?')) {
                $query = http_build_query($params);
                if ($query !== '') {
                    return $paymentUrl . '?' . $query;
                }
            }

            return $paymentUrl;
        }

        $static = trim((string) $merchant->hamropay_qr_payload);
        if ($static !== '') {
            return $static;
        }

        return $paymentUrl !== '' ? $paymentUrl : null;
    }

    private function formatSession(PodPaymentSession $session): array
    {
        $session->loadMissing(['merchant', 'shipment']);
        $payload = (array) $session->response_payload;
        $params = (array) ($payload['params'] ?? []);
        $paymentUrl = (string) ($payload['payment_url'] ?? $session->checkout_url);
        $qrString = $session->qr_payload;

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
            ],
            'amount' => number_format((float) $session->amount, 2, '.', ''),
            'currency' => $session->currency,
            'settlement_destination' => $session->settlement_destination,
            'qr_string' => $qrString,
            'payment_url' => $paymentUrl,
            'params' => $params,
            'payment' => [
                'channel' => 'qr',
                'purpose' => 'pod',
                'qr' => [
                    'format' => $session->qr_image_url ? 'image_url' : ($qrString ? 'payload' : null),
                    'image_url' => $session->qr_image_url,
                    'payload' => $qrString,
                ],
                'checkout_url' => $paymentUrl,
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

    private function cacheKey(string $merchantTxnId): string
    {
        return 'pod_hamropay_intent_' . $merchantTxnId;
    }

    private function shipmentAmount(Shipment $shipment): float
    {
        return round((float) ($shipment->total_collectable_amount ?: $shipment->total_collectable ?: $shipment->pod_amount), 2);
    }

    private function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}