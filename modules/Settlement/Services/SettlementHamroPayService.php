<?php

namespace Modules\Settlement\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Services\PaymentGatewayAccountService;
use Modules\Merchant\Models\Merchant;
use Modules\Settlement\Models\MerchantSettlement;
use Modules\Shipment\Models\Shipment;

/**
 * Pay POD settlement to merchant wallet via HamroPay.
 * Payer credentials: branch account (from settlement shipments) → company fallback.
 * Payee: merchant hamropay_merchant_id / hamropay_business_id as subMerchantId.
 */
class SettlementHamroPayService
{
    public function __construct(private PaymentGatewayAccountService $accounts)
    {
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

        $branchId = $this->branchIdForSettlement($settlement);
        $account = $this->accounts->resolvePayerAccount($branchId, 'hamropay');
        $client = $this->accounts->hamroPayClientFromAccount($account);

        if (! $account && (! filled(config('hamropay.api_base_url')) || ! filled(config('hamropay.client_id')))) {
            throw ValidationException::withMessages([
                'hamropay' => ['No HamroPay account configured for branch or company. Save one under Payment gateways.'],
            ]);
        }

        $merchant = Merchant::query()->find($settlement->merchant_id);
        $subMerchantId = $merchant?->hamropay_merchant_id ?: $merchant?->hamropay_business_id;
        if (! $subMerchantId) {
            throw ValidationException::withMessages([
                'merchant' => ['Register this merchant on HamroPay (KYB) before paying POD settlements.'],
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
            'checkout' => $params,
            'provider' => $session,
        ];
    }

    public function confirmFromProvider(MerchantSettlement $settlement, string $merchantTxnId): array
    {
        $branchId = $this->branchIdForSettlement($settlement);
        $account = $this->accounts->resolvePayerAccount($branchId, 'hamropay');
        $client = $this->accounts->hamroPayClientFromAccount($account);
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
}
