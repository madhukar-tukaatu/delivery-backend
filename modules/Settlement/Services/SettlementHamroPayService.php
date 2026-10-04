<?php

namespace Modules\Settlement\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Services\PaymentGatewayAccountService;
use Modules\Merchant\Models\Merchant;
use Modules\Setting\Services\MarketplacePaymentUrlResolver;
use Modules\Settlement\Models\MerchantSettlement;
use Modules\Shipment\Models\Shipment;

/**
 * Pay POD settlement to merchant wallet via HamroPay.
 * Payer credentials: branch account (from settlement shipments) → company fallback.
 * Payee: Merchant::hamroPaySubMerchantId() — KYB id first, else STORE- suffix / external_store_id.
 */
class SettlementHamroPayService
{
    public function __construct(
        private PaymentGatewayAccountService $accounts,
        private MarketplacePaymentUrlResolver $paymentUrls,
    ) {
    }

    protected function branchIdForSettlement(MerchantSettlement $settlement): ?int
    {
        $shipmentIds = $settlement->items()->pluck('shipment_id')->filter()->all();
        if (! $shipmentIds) {
            return null;
        }

        $shipment = Shipment::query()->whereIn('id', $shipmentIds)->orderByDesc('id')->first();
        if (! $shipment) {
            return null;
        }

        return $shipment->destination_branch_id
            ?? $shipment->current_branch_id
            ?? $shipment->origin_branch_id
            ?? null;
    }

    public function createPayoutSession(MerchantSettlement $settlement): array
    {
        if (in_array(strtolower((string) $settlement->status), ['settled', 'paid'], true)) {
            throw ValidationException::withMessages([
                'settlement' => ['This settlement is already paid.'],
            ]);
        }

        $amount = (float) ($settlement->final_payable_amount ?? 0);
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'settlement' => ['Payable amount must be greater than zero.'],
            ]);
        }

        $merchant = Merchant::query()->find($settlement->merchant_id);
        $shipmentIds = $settlement->items()->pluck('shipment_id')->filter()->all();
        $shipment = $shipmentIds
            ? Shipment::query()->whereIn('id', $shipmentIds)->orderByDesc('id')->first()
            : null;
        $paymentTarget = $this->paymentUrls->resolve($shipment, $merchant);
        if ($paymentTarget['api_base_url'] === '') {
            $label = $paymentTarget['marketplace']?->name ?: ($merchant->name ?? 'this store');
            throw ValidationException::withMessages([
                'payment' => ['No payment API base URL for '.$label.'. Set API base URL on Admin -> Marketplaces.'],
            ]);
        }

        $branchId = $this->branchIdForSettlement($settlement);
        $marketplaceId = $paymentTarget['marketplace']?->id ? (int) $paymentTarget['marketplace']->id : null;
        $account = $this->accounts->resolveMarketplacePayerAccount($marketplaceId, $branchId, 'hamropay');
        $client = $this->clientOnMarketplaceHost(
            $this->accounts->hamroPayClientFromAccount($account),
            $paymentTarget,
        );

        if (! $account && (! filled(config('hamropay.api_base_url')) || ! filled(config('hamropay.client_id')))) {
            throw ValidationException::withMessages([
                'hamropay' => ['No HamroPay account configured for branch or company. Save one under Payment gateways.'],
            ]);
        }

        $merchant = Merchant::query()->find($settlement->merchant_id);
        $subMerchantId = $merchant?->hamroPaySubMerchantId();
        if (! $subMerchantId) {
            throw ValidationException::withMessages([
                'merchant' => ['Merchant needs external_store_id (e.g. STORE-00008) or HamroPay KYB id before POD settlement payout.'],
            ]);
        }

        $paisa = (int) round($amount * 100);
        $txnId = 'SETPAY-'.$settlement->id.'-'.Str::upper(Str::random(8));
        $platformMerchantId = $client->platformMerchantId()
            ?: ($account?->credential('merchant_id') ?: config('hamropay.merchant_id'));

        $session = $client->createSession([
            'merchantTxnId' => $txnId,
            'transactionAmount' => $paisa,
            'remarks' => 'POD settlement '.$settlement->settlement_number,
            'metadata' => [
                'payment_api_base_url' => $paymentTarget['api_base_url'],
                'marketplace_code' => $paymentTarget['marketplace']?->code,
            ],
        ], $platformMerchantId ? (string) $platformMerchantId : null, (string) $subMerchantId);

        $sessionId = data_get($session, 'sessionId')
            ?? data_get($session, 'data.sessionId')
            ?? data_get($session, 'session_id');

        if (! $sessionId) {
            Log::warning('hamropay.pod_settlement_session_failed', [
                'settlement_id' => $settlement->id,
                'response' => $session,
            ]);
            throw ValidationException::withMessages([
                'hamropay' => [data_get($session, 'message', 'HamroPay did not return a session id.')],
            ]);
        }

        $params = $client->buildCheckoutParams(
            (string) $sessionId,
            $txnId,
            $paisa,
            'POD settlement '.$settlement->settlement_number,
            $platformMerchantId ? (string) $platformMerchantId : null,
            null,
            null,
            (string) $subMerchantId,
        );

        $settlement->forceFill([
            'payment_method' => 'hamropay',
            'bank_reference_number' => $txnId,
        ])->save();

        return [
            'settlement_id' => $settlement->id,
            'merchant_txn_id' => $txnId,
            'session_id' => $sessionId,
            'amount' => $amount,
            'amount_paisa' => $paisa,
            'payer_account' => $account ? [
                'id' => $account->id,
                'owner_type' => $account->owner_type,
                'owner_id' => $account->owner_id,
            ] : ['owner_type' => 'env_fallback'],
            'payee_merchant_hamropay_id' => $subMerchantId,
            'gateway_url' => $client->getGatewayUrl(),
            'payment_api_base_url' => $paymentTarget['api_base_url'],
            'payment_request_url' => $paymentTarget['payment_request_url'],
            'marketplace' => $paymentTarget['marketplace'] ? [
                'id' => $paymentTarget['marketplace']->id,
                'code' => $paymentTarget['marketplace']->code,
                'name' => $paymentTarget['marketplace']->name,
            ] : null,
            'checkout' => $params,
            'provider' => $session,
        ];
    }

    public function confirmFromProvider(MerchantSettlement $settlement, string $merchantTxnId): array
    {
        $merchant = Merchant::query()->find($settlement->merchant_id);
        $shipmentIds = $settlement->items()->pluck('shipment_id')->filter()->all();
        $shipment = $shipmentIds
            ? Shipment::query()->whereIn('id', $shipmentIds)->orderByDesc('id')->first()
            : null;
        $paymentTarget = $this->paymentUrls->resolve($shipment, $merchant);
        $branchId = $this->branchIdForSettlement($settlement);
        $marketplaceId = $paymentTarget['marketplace']?->id ? (int) $paymentTarget['marketplace']->id : null;
        $account = $this->accounts->resolveMarketplacePayerAccount($marketplaceId, $branchId, 'hamropay');
        $client = $this->clientOnMarketplaceHost(
            $this->accounts->hamroPayClientFromAccount($account),
            $paymentTarget,
        );
        $tx = $client->getTransaction($merchantTxnId);
        $status = strtolower((string) (
            data_get($tx, 'status')
            ?? data_get($tx, 'transactionStatus')
            ?? data_get($tx, 'data.status')
            ?? ''
        ));

        $paid = in_array($status, ['success', 'successful', 'paid', 'completed', 'complete'], true)
            || (bool) data_get($tx, 'success');

        return [
            'paid' => $paid,
            'status' => $status,
            'provider' => $tx,
        ];
    }

    /**
     * HamroPay checkout for this store hits that marketplace API host
     * (api.tukaatu.com, api.fca.com.np, ...) instead of one global URL.
     */
    private function clientOnMarketplaceHost(\Modules\Billing\Services\HamroPayService $client, array $paymentTarget): \Modules\Billing\Services\HamroPayService
    {
        $base = trim((string) ($paymentTarget['api_base_url'] ?? ''));
        if ($base === '') {
            return $client;
        }

        return $client->withEndpoints($base);
    }
}
