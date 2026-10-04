<?php

declare(strict_types=1);

namespace Modules\POD\Services;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Delivery\Models\DeliveryAssignment;
use Modules\Merchant\Models\Merchant;
use Modules\POD\Events\PodPaymentUpdated;
use Modules\POD\Jobs\RequestTukaatuPodPaymentJob;
use Modules\POD\Models\PodPaymentSession;
use Modules\Shipment\Models\Shipment;
use Modules\Setting\Services\MarketplacePaymentUrlResolver;
use Modules\Setting\Services\MarketplaceApiKeyIssuer;

/**
 * Doorstep POD online payment via Tukaatu Marketplace.
 *
 * Express does NOT call HamroPay createSession for rider QR. Flow:
 *  1) Rider POST pod-payment -> pending local session + async job
 *  2) Job POST {marketplace api_base_url}/api/v1/gateway/payments/pod-qr (Key/Secret)
 *  3) Tukaatu callbacks Express (pod_payment.ready|paid|failed)
 *  4) Rider polls GET until qr_string / paid
 *
 * HQ HamroPay (settlements / gateway pod-qr for stores) stays on HamroPayPodPaymentService.
 */
final class TukaatuPodPaymentService
{
    private const ACTIVE = ['pending', 'ready', 'paid'];

    public function createForDelivery(
        DeliveryAssignment $delivery,
        Shipment $shipment,
        ?string $idempotencyKey = null,
    ): array {
        $shipment->loadMissing('merchant');
        $merchant = $shipment->merchant;
        $this->assertEligibleShipment($shipment, $merchant);
        // Fail fast with 422 before creating a pending session when Express cannot
        // send the marketplace key (e.g. key_encrypted missing). Do not pretend a
        // provider call happened.
        $this->assertMarketplaceOutboundReady($shipment, $merchant);

        $existing = PodPaymentSession::query()
            ->where('shipment_id', $shipment->id)
            ->whereIn('status', self::ACTIVE)
            ->latest('id')
            ->first();

        if ($existing) {
            if (in_array($existing->status, ['pending', 'ready'], true) && $existing->expires_at?->isPast()) {
                $existing->update(['status' => 'expired']);
            } else {
                // Re-dispatch job if still waiting for QR and no outbound yet / errored.
                if ($this->needsOutboundRetry($existing)) {
                    $this->dispatchOutbound($existing->id);
                    $existing->refresh();
                }

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

        $amount = $this->shipmentAmount($shipment);
        $sessionId = 'PODSESS' . $shipment->id . Str::upper(Str::random(10));
        if (strlen($sessionId) > 64) {
            $sessionId = substr($sessionId, 0, 64);
        }

        $attrs = [
            'merchant_id' => $merchant->id,
            'shipment_id' => $shipment->id,
            'payment_session_id' => $sessionId,
            'idempotency_key' => $idempotencyKey,
            'external_store_id' => (string) ($merchant->external_store_id ?: ''),
            'external_platform' => $merchant->external_platform,
            'merchant_order_id' => $shipment->merchant_order_id,
            'tracking_number' => $shipment->tracking_number,
            'amount' => $amount,
            'currency' => 'NPR',
            'status' => 'pending',
            'settlement_destination' => 'merchant',
            'expires_at' => now()->addMinutes(30),
        ];

        if (Schema::hasColumn('pod_payment_sessions', 'delivery_assignment_id')) {
            $attrs['delivery_assignment_id'] = $delivery->id;
        }

        $session = PodPaymentSession::query()->create($attrs);

        $this->dispatchOutbound($session->id);
        $session->refresh();

        // After sync (local) or async enqueue, return full session so FE sees last_error/failed immediately when outbound cannot run.
        return $this->formatSession($session);
    }

    public function currentForDelivery(DeliveryAssignment $delivery, Shipment $shipment, bool $refresh = false): ?array
    {
        $session = PodPaymentSession::query()
            ->where('shipment_id', $shipment->id)
            ->when(
                Schema::hasColumn('pod_payment_sessions', 'delivery_assignment_id'),
                fn ($q) => $q->where(function ($inner) use ($delivery) {
                    $inner->where('delivery_assignment_id', $delivery->id)
                        ->orWhereNull('delivery_assignment_id');
                })
            )
            ->latest('id')
            ->first();

        if (! $session) {
            return null;
        }

        if ($refresh && $this->needsOutboundRetry($session, allowFailed: true)) {
            $this->prepareSessionForRetry($session);
            $this->dispatchOutbound($session->id);
            $session->refresh();
        }

        return $this->formatSession($session);
    }

    /**
     * Alias used by DeliveryWorkflowService payment-session thin wrappers.
     */
    public function createForShipment(Shipment $shipment, ?string $idempotencyKey = null, ?DeliveryAssignment $delivery = null): array
    {
        $delivery = $delivery ?: DeliveryAssignment::query()
            ->where('shipment_id', $shipment->id)
            ->whereIn('status', ['out_for_delivery', 'accepted', 'assigned'])
            ->latest('id')
            ->first();

        if (! $delivery) {
            throw ValidationException::withMessages([
                'delivery' => ['No active delivery assignment found for this shipment.'],
            ]);
        }

        return $this->createForDelivery($delivery, $shipment, $idempotencyKey);
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

        // Refresh re-dispatches outbound when still pending/ready without QR, or failed (explicit retry).
        if ($refresh && $this->needsOutboundRetry($session, allowFailed: true)) {
            $this->prepareSessionForRetry($session);
            $this->dispatchOutbound($session->id);
            $session->refresh();
        }

        return $this->formatSession($session);
    }

    public function assertPaid(Shipment $shipment, string $paymentSessionId): PodPaymentSession
    {
        $paymentSessionId = trim($paymentSessionId);

        $session = PodPaymentSession::query()
            ->where('shipment_id', $shipment->id)
            ->where('merchant_id', $shipment->merchant_id)
            ->where(function ($q) use ($paymentSessionId) {
                $q->where('payment_session_id', $paymentSessionId)
                    ->orWhere('merchant_txn_id', $paymentSessionId)
                    ->orWhere('provider_reference', $paymentSessionId)
                    ->orWhere('transaction_id', $paymentSessionId);
            })
            ->latest('id')
            ->first();

        if (! $session) {
            throw ValidationException::withMessages([
                'payment_session_id' => ['The payment session does not belong to this shipment.'],
            ]);
        }

        if (! $session->isPaid()) {
            throw ValidationException::withMessages([
                'payment_session_id' => [
                    'Tukaatu has not confirmed this payment yet. Wait for the customer to complete the QR payment.',
                ],
            ]);
        }

        $this->assertSessionAmount($shipment, $session);

        if ($session->settlement_destination && $session->settlement_destination !== 'merchant') {
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
     * Handle inbound Tukaatu POD callbacks on the Express payment-events URL.
     *
     * Events (X-Tukaatu-Event):
     *  - pod_payment.ready  (or session update with qr_string) -> status=ready
     *  - pod_payment.paid
     *  - pod_payment.failed
     */
    /**
     * Delivery POD online only. Looks up pod_payment_sessions by session id
     * and only if that row is tied to a delivery assignment.
     *
     * @return array<string, mixed>
     */
    public function handleDeliveryPodCallback(
        string $event,
        array $payload,
        string $rawBody,
        string $timestamp,
        string $signature,
        ?string $eventId = null,
    ): array {
        $this->verifyCallbackSignature($rawBody, $timestamp, $signature);

        $normalized = strtolower(str_replace(['-', ' '], '_', trim($event)));
        $kind = match ($normalized) {
            'pod_payment.ready', 'pod_payment_ready' => 'ready',
            'pod_payment.paid', 'pod_payment_paid' => 'paid',
            'pod_payment.failed', 'pod_payment_failed' => 'failed',
            default => null,
        };
        if ($kind === null) {
            abort(422, 'Unsupported delivery POD event.');
        }

        $session = $this->findDeliveryPodSession($payload);
        if (! $session) {
            Log::warning('Delivery POD callback: session not found', [
                'event' => $normalized,
            ]);
            abort(404, 'Payment session was not found.');
        }

        if ($eventId && $session->last_event_id === $eventId) {
            return $this->formatSession($session);
        }

        if ($kind === 'paid') {
            $this->applyPaid($session, $payload);
        } elseif ($kind === 'failed') {
            $this->applyFailed($session, $payload);
        } else {
            $this->applyReady($session, $payload);
        }

        if ($eventId) {
            $session->update([
                'last_event_id' => $eventId,
                'response_payload' => array_merge((array) $session->response_payload, [
                    'last_callback' => $payload,
                    'last_event' => $normalized,
                ]),
            ]);
        }

        $session = $session->fresh(['merchant', 'shipment']);
        event(new PodPaymentUpdated($session));

        if ($session->isPaid()) {
            $this->syncLocalPayment($session);
        }

        return $this->formatSession($session);
    }

    private function findDeliveryPodSession(array $payload): ?PodPaymentSession
    {
        $sessionId = trim((string) (
            $payload['payment_session_id']
            ?? $payload['session_id']
            ?? data_get($payload, 'data.payment_session_id')
            ?? data_get($payload, 'data.session_id')
            ?? ''
        ));
        if ($sessionId === '') {
            return null;
        }

        $query = PodPaymentSession::query()->where('payment_session_id', $sessionId);
        if (Schema::hasColumn('pod_payment_sessions', 'delivery_assignment_id')) {
            $query->whereNotNull('delivery_assignment_id');
        }

        return $query->first();
    }

    public function handleTukaatuEvent(
        string $event,
        array $payload,
        string $rawBody,
        string $timestamp,
        string $signature,
        ?string $eventId = null,
    ): array {
        $this->verifyCallbackSignature($rawBody, $timestamp, $signature);

        $event = strtolower(trim($event));
        $normalized = str_replace(['-', ' '], ['_', '_'], $event);

        $session = $this->findSessionFromPayload($payload);

        if (! $session) {
            Log::warning('Tukaatu POD callback: session not found', [
                'event' => $event,
                'payload_keys' => array_keys($payload),
            ]);
            abort(404, 'Payment session was not found.');
        }

        if ($eventId && $session->last_event_id === $eventId) {
            return $this->formatSession($session);
        }

        if (in_array($normalized, ['pod_payment.paid', 'pod.payment.paid', 'payment.paid'], true)
            || str_ends_with($normalized, 'pod_payment.paid')
            || $normalized === 'pod_payment_paid'
        ) {
            $this->applyPaid($session, $payload);
        } elseif (in_array($normalized, ['pod_payment.failed', 'pod.payment.failed', 'payment.failed'], true)
            || $normalized === 'pod_payment_failed'
        ) {
            $this->applyFailed($session, $payload);
        } elseif (
            in_array($normalized, ['pod_payment.ready', 'pod.payment.ready', 'pod_payment.created', 'pod_payment.updated', 'pod_payment.qr'], true)
            || $normalized === 'pod_payment_ready'
            || $this->payloadHasQr($payload)
        ) {
            $this->applyReady($session, $payload);
        } else {
            // Generic session update: accept QR / txn fields without forcing status.
            $this->applyReady($session, $payload, preserveStatus: true);
        }

        if ($eventId) {
            $session->update([
                'last_event_id' => $eventId,
                'response_payload' => array_merge((array) $session->response_payload, [
                    'last_callback' => $payload,
                    'last_event' => $event,
                ]),
            ]);
        }

        $session = $session->fresh(['merchant', 'shipment']);
        event(new PodPaymentUpdated($session));

        if ($session->isPaid()) {
            $this->syncLocalPayment($session);
        }

        return $this->formatSession($session);
    }

    private function applyPaid(PodPaymentSession $session, array $payload): void
    {
        $merchantTxnId = trim((string) (
            $payload['merchant_txn_id']
            ?? $payload['merchantTxnId']
            ?? data_get($payload, 'data.merchant_txn_id')
            ?? $session->merchant_txn_id
            ?? ''
        ));
        $transactionId = trim((string) (
            $payload['transaction_id']
            ?? $payload['transactionId']
            ?? data_get($payload, 'data.transaction_id')
            ?? ''
        ));
        $paidAt = $payload['paid_at'] ?? data_get($payload, 'data.paid_at') ?? now()->toIso8601String();

        $updates = [
            'status' => 'paid',
            'paid_at' => $this->parseDate($paidAt) ?: now(),
            'failed_at' => null,
            'last_error' => null,
            'settlement_destination' => 'merchant',
        ];

        if ($merchantTxnId !== '') {
            $updates['merchant_txn_id'] = $merchantTxnId;
        }
        if ($transactionId !== '') {
            $updates['transaction_id'] = $transactionId;
            $updates['provider_reference'] = $transactionId;
        } elseif ($merchantTxnId !== '') {
            $updates['provider_reference'] = $merchantTxnId;
        }

        $this->mergeQrFields($updates, $payload);
        $session->update($updates);
    }

    private function applyFailed(PodPaymentSession $session, array $payload): void
    {
        $session->update([
            'status' => 'failed',
            'failed_at' => now(),
            'last_error' => trim((string) ($payload['reason'] ?? $payload['message'] ?? 'Payment failed')),
        ]);
    }

    private function applyReady(PodPaymentSession $session, array $payload, bool $preserveStatus = false): void
    {
        $updates = [
            'last_error' => null,
        ];

        if (! $preserveStatus && ! $session->isPaid()) {
            $updates['status'] = 'ready';
        }

        $merchantTxnId = trim((string) (
            $payload['merchant_txn_id']
            ?? $payload['merchantTxnId']
            ?? data_get($payload, 'data.merchant_txn_id')
            ?? ''
        ));
        if ($merchantTxnId !== '') {
            $updates['merchant_txn_id'] = $merchantTxnId;
        }

        $this->mergeQrFields($updates, $payload);
        $session->update($updates);
    }

    private function mergeQrFields(array &$updates, array $payload): void
    {
        $qr = trim((string) (
            $payload['qr_string']
            ?? $payload['qr_payload']
            ?? data_get($payload, 'payment.qr.payload')
            ?? data_get($payload, 'data.qr_string')
            ?? ''
        ));
        $url = trim((string) (
            $payload['payment_url']
            ?? $payload['checkout_url']
            ?? data_get($payload, 'payment.checkout_url')
            ?? data_get($payload, 'data.payment_url')
            ?? ''
        ));
        $image = trim((string) (
            $payload['qr_image_url']
            ?? data_get($payload, 'payment.qr.image_url')
            ?? ''
        ));

        if ($qr !== '') {
            $updates['qr_payload'] = $qr;
        }
        if ($url !== '') {
            $updates['checkout_url'] = $url;
        }
        if ($image !== '') {
            $updates['qr_image_url'] = $image;
        }
    }

    private function payloadHasQr(array $payload): bool
    {
        foreach (['qr_string', 'qr_payload', 'payment_url', 'checkout_url'] as $key) {
            if (filled(data_get($payload, $key))) {
                return true;
            }
        }

        return filled(data_get($payload, 'payment.qr.payload'))
            || filled(data_get($payload, 'data.qr_string'))
            || filled(data_get($payload, 'data.payment_url'));
    }

    private function findSessionFromPayload(array $payload): ?PodPaymentSession
    {
        $sessionId = trim((string) (
            $payload['session_id']
            ?? $payload['payment_session_id']
            ?? data_get($payload, 'data.session_id')
            ?? ''
        ));
        $merchantTxnId = trim((string) (
            $payload['merchant_txn_id']
            ?? $payload['merchantTxnId']
            ?? data_get($payload, 'data.merchant_txn_id')
            ?? ''
        ));
        $tracking = trim((string) (
            $payload['tracking_number']
            ?? data_get($payload, 'shipment.tracking_number')
            ?? ''
        ));
        $externalOrderId = trim((string) (
            $payload['external_order_id']
            ?? $payload['merchant_order_id']
            ?? data_get($payload, 'shipment.merchant_order_id')
            ?? ''
        ));

        if ($sessionId !== '') {
            $found = PodPaymentSession::query()->where('payment_session_id', $sessionId)->latest('id')->first();
            if ($found) {
                return $found;
            }
        }

        if ($merchantTxnId !== '') {
            $found = PodPaymentSession::query()
                ->where(function ($q) use ($merchantTxnId) {
                    $q->where('merchant_txn_id', $merchantTxnId)
                        ->orWhere('payment_session_id', $merchantTxnId);
                })
                ->latest('id')
                ->first();
            if ($found) {
                return $found;
            }
        }

        if ($tracking !== '') {
            $found = PodPaymentSession::query()
                ->where('tracking_number', $tracking)
                ->whereIn('status', ['pending', 'ready'])
                ->latest('id')
                ->first();
            if ($found) {
                return $found;
            }
        }

        if ($externalOrderId !== '') {
            return PodPaymentSession::query()
                ->where('merchant_order_id', $externalOrderId)
                ->whereIn('status', ['pending', 'ready'])
                ->latest('id')
                ->first();
        }

        return null;
    }

    private function verifyCallbackSignature(string $rawBody, string $timestamp, string $signature): void
    {
        $secret = trim((string) config('services.tukaatu.callback_secret'));

        // Fall back to legacy Store Manager webhook secret so one endpoint can serve both.
        if ($secret === '') {
            $secret = trim((string) config('services.store_manager.payment.shared_secret'));
        }

        if ($secret === '') {
            // Local/dev: allow unsigned when neither secret is configured.
            if (app()->environment(['local', 'testing'])) {
                return;
            }
            abort(401, 'Tukaatu callback secret is not configured (TUKAATU_CALLBACK_SECRET).');
        }

        if ($timestamp !== '' && ctype_digit($timestamp)) {
            $tolerance = (int) config('services.tukaatu.webhook_tolerance', 300);
            if (abs(now()->timestamp - (int) $timestamp) > $tolerance) {
                abort(401, 'Invalid Tukaatu callback timestamp.');
            }
        }

        if ($signature === '') {
            abort(401, 'X-Tukaatu-Signature header is required.');
        }

        $candidates = [];
        if ($timestamp !== '') {
            $candidates[] = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
        }
        $candidates[] = hash_hmac('sha256', $rawBody, $secret);

        foreach ($candidates as $expected) {
            if (hash_equals($expected, $signature)) {
                return;
            }
        }

        abort(401, 'Invalid Tukaatu callback signature.');
    }

    private function syncLocalPayment(PodPaymentSession $session): void
    {
        // Intentionally light: delivery.completed webhook carries paid + payment_reference
        // when the rider marks delivered. This hook is for future POD record linkage.
        Log::info('Tukaatu POD session paid', [
            'payment_session_id' => $session->payment_session_id,
            'merchant_txn_id' => $session->merchant_txn_id,
            'transaction_id' => $session->transaction_id ?: $session->provider_reference,
            'shipment_id' => $session->shipment_id,
            'amount' => $session->amount,
        ]);
    }


    /**
     * Ensure Express can POST POD to this store's marketplace using the issued key.
     * Throws ValidationException with a short rider-facing payment error.
     * Detailed reason is logged (never includes secrets).
     */
    private function assertMarketplaceOutboundReady(Shipment $shipment, Merchant $merchant): void
    {
        $resolved = app(MarketplacePaymentUrlResolver::class)->resolve($shipment, $merchant);
        $marketplace = $resolved['marketplace'];

        if (! $marketplace) {
            throw ValidationException::withMessages([
                'payment' => [
                    'Online payment is not set up for this store yet. An admin must attach the store to a marketplace.',
                ],
            ]);
        }

        $label = trim((string) $marketplace->name);
        if ($marketplace->code) {
            $label .= ' ('.$marketplace->code.')';
        }

        $url = (string) ($resolved['payment_request_url'] ?? '');
        $host = $url !== '' ? (string) parse_url($url, PHP_URL_HOST) : '';
        if ($url === '' || $host === '') {
            Log::warning('POD create blocked: marketplace missing API base URL', [
                'marketplace_id' => $marketplace->id,
                'marketplace_code' => $marketplace->code,
                'shipment_id' => $shipment->id,
            ]);
            throw ValidationException::withMessages([
                'payment' => [
                    'Online payment is not set up for this marketplace yet. An admin must set the marketplace API base URL.',
                ],
            ]);
        }

        $creds = app(MarketplaceApiKeyIssuer::class)->resolveOutboundCredentials(
            (int) $marketplace->id,
            $label
        );

        if ($creds['ok'] ?? false) {
            return;
        }

        Log::warning('POD create blocked: marketplace outbound credentials not ready', [
            'marketplace_id' => $marketplace->id,
            'marketplace_code' => $marketplace->code,
            'shipment_id' => $shipment->id,
            'code' => $creds['code'] ?? null,
            'key_prefix' => $creds['key_prefix'] ?? '',
            'detail' => $creds['error'] ?? null,
        ]);

        $rider = trim((string) ($creds['rider_error'] ?? ''));
        if ($rider === '') {
            $rider = MarketplaceApiKeyIssuer::RIDER_SETUP_MESSAGE;
        }

        throw ValidationException::withMessages([
            'payment' => [$rider],
        ]);
    }

    private function assertEligibleShipment(Shipment $shipment, ?Merchant $merchant): void
    {
        if (! $merchant) {
            throw ValidationException::withMessages([
                'payment' => ['Shipment merchant is missing.'],
            ]);
        }

        $paymentType = strtolower((string) $shipment->payment_type);
        if (! in_array($paymentType, ['pod', 'cod', 'cash_on_delivery'], true)
            && $this->shipmentAmount($shipment) <= 0
        ) {
            throw ValidationException::withMessages([
                'payment' => ['Online payment sessions are available only for POD shipments with a collectable amount.'],
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

    private function shipmentAmount(Shipment $shipment): float
    {
        return round((float) ($shipment->total_collectable_amount ?: $shipment->total_collectable ?: $shipment->pod_amount), 2);
    }

    private function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private function parseDate(mixed $value): ?\Carbon\Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return \Carbon\Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }


    /**
     * Local + non-sync queue: run outbound in-process so FE is not stuck on a idle/misconfigured worker.
     * Production keeps async dispatch.
     */
    private function dispatchOutbound(int $sessionId): void
    {
        $queue = (string) config('queue.default', 'sync');
        $useSync = app()->environment('local') && $queue !== 'sync';

        if ($useSync) {
            Bus::dispatchSync(new RequestTukaatuPodPaymentJob($sessionId));

            return;
        }

        RequestTukaatuPodPaymentJob::dispatch($sessionId);
    }

    private function needsOutboundRetry(PodPaymentSession $session, bool $allowFailed = false): bool
    {
        if ($allowFailed && $session->status === 'failed') {
            return true;
        }

        if (! in_array($session->status, ['pending', 'ready'], true)) {
            return false;
        }

        if (filled($session->qr_payload) || filled($session->checkout_url)) {
            return false;
        }

        // No successful outbound recorded, or a previous attempt left last_error.
        $outbound = data_get($session->response_payload, 'tukaatu_request');
        if (! is_array($outbound) || filled($session->last_error)) {
            return true;
        }

        $http = (int) ($outbound['http_status'] ?? 0);

        return $http < 200 || $http >= 300;
    }

    private function prepareSessionForRetry(PodPaymentSession $session): void
    {
        if ($session->status !== 'failed') {
            return;
        }

        $session->update([
            'status' => 'pending',
            'failed_at' => null,
            'last_error' => null,
        ]);
        $session->refresh();
    }

    private function formatCreateResponse(PodPaymentSession $session): array
    {
        return [
            'session_id' => $session->payment_session_id,
            'payment_session_id' => $session->payment_session_id,
            'status' => 'pending',
            'amount' => number_format((float) $session->amount, 2, '.', ''),
            'currency' => $session->currency ?: 'NPR',
        ];
    }

    public function formatSession(PodPaymentSession $session): array
    {
        $session->loadMissing(['merchant', 'shipment']);

        $qrPayload = is_string($session->qr_payload) ? trim($session->qr_payload) : '';
        $checkoutUrl = is_string($session->checkout_url) ? trim($session->checkout_url) : '';
        $qrString = $qrPayload !== '' ? $qrPayload : null;
        $merchantTxnId = $session->merchant_txn_id ?: $session->payment_session_id;
        $params = data_get($session->response_payload, 'provider_checkout.params');
        if (! is_array($params)) {
            $params = null;
        }
        $transactionId = $session->transaction_id ?: $session->provider_reference;
        $shipment = $session->shipment;

        return [
            'session_id' => $session->payment_session_id,
            'payment_session_id' => $session->payment_session_id,
            'merchant_txn_id' => $merchantTxnId,
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
            'qr_string' => $qrString,
            'payment_url' => $checkoutUrl !== '' ? $checkoutUrl : null,
            'params' => $params,
            'payment_method' => 'online',
            'payment_reference' => $transactionId ?: $merchantTxnId,
            'transaction_id' => $transactionId,
            'paid' => $session->isPaid(),
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
            'last_error' => $session->last_error,
        ];
    }
}
