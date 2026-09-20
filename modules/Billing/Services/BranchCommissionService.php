<?php

namespace Modules\Billing\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Models\BranchCommissionBill;
use Modules\Billing\Models\BranchCommissionSettlement;
use Modules\Settlement\Services\SettlementWorkflowService;
use Modules\Shipment\Models\Shipment;

/**
 * HQ (Tukaatu Express superadmin) commission owed by the delivery branch
 * after each successful delivery. Independent of merchant POD settlements
 * and merchant delivery-fee invoices.
 */
class BranchCommissionService
{
    public function __construct(
        private SettlementWorkflowService $settlements,
        private PaymentGatewayAccountService $accounts,
    ) {
    }

    public function commissionRatePercent(?int $branchId = null): float
    {
        // Optional per-branch override in settings: commission.branch.{id}.percent
        if ($branchId) {
            $row = \Modules\Setting\Models\Setting::query()
                ->where('key', "commission.branch.{$branchId}.percent")
                ->first();
            if ($row && is_numeric($row->value)) {
                return (float) $row->value;
            }
        }

        $row = \Modules\Setting\Models\Setting::query()
            ->where('key', 'commission.hq.percent')
            ->first();

        if ($row && is_numeric($row->value)) {
            return (float) $row->value;
        }

        return (float) env('HQ_COMMISSION_PERCENT', 5);
    }

    public function setCommissionRatePercent(float $percent, ?int $branchId = null): void
    {
        $key = $branchId
            ? "commission.branch.{$branchId}.percent"
            : 'commission.hq.percent';

        \Modules\Setting\Models\Setting::updateOrCreate(
            ['key' => $key],
            ['value' => (string) $percent, 'type' => 'number'],
        );
    }

    protected function responsibleBranchId(Shipment $shipment): ?int
    {
        // Last-mile / destination branch owes HQ commission on completed delivery.
        return $shipment->destination_branch_id
            ?? $shipment->current_branch_id
            ?? $shipment->origin_branch_id
            ?? null;
    }

    public function autoEnsureForShipment(Shipment $shipment): ?BranchCommissionBill
    {
        $shipment->refresh();

        if (strtolower((string) $shipment->status) !== 'delivered') {
            return null;
        }

        $branchId = $this->responsibleBranchId($shipment);
        if (! $branchId) {
            return null;
        }

        $base = $this->settlements->checkoutDeliveryCharge($shipment);
        if ($base <= 0) {
            return null;
        }

        $rate = $this->commissionRatePercent((int) $branchId);
        if ($rate <= 0) {
            return null;
        }

        $amount = round($base * ($rate / 100), 2);
        if ($amount <= 0) {
            return null;
        }

        return DB::transaction(function () use ($shipment, $branchId, $base, $rate, $amount) {
            $existing = BranchCommissionBill::query()
                ->where('shipment_id', $shipment->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            return BranchCommissionBill::create([
                'bill_number' => 'HQ-COM-'.now()->format('YmdHis').'-'.random_int(100, 999),
                'branch_id' => $branchId,
                'shipment_id' => $shipment->id,
                'merchant_id' => $shipment->merchant_id,
                'delivery_charge_base' => round($base, 2),
                'commission_rate' => $rate,
                'commission_amount' => $amount,
                'currency' => 'NPR',
                'status' => 'unpaid',
            ]);
        });
    }

    public function createSettlement(int $branchId, array $billIds = [], float $adjustments = 0): BranchCommissionSettlement
    {
        return DB::transaction(function () use ($branchId, $billIds, $adjustments) {
            $q = BranchCommissionBill::query()
                ->where('branch_id', $branchId)
                ->where('status', 'unpaid')
                ->whereNull('settlement_id')
                ->lockForUpdate();

            if ($billIds) {
                $q->whereIn('id', $billIds);
            }

            $bills = $q->get();
            if ($bills->isEmpty()) {
                throw ValidationException::withMessages([
                    'bills' => ['No unpaid HQ commission bills for this branch.'],
                ]);
            }

            $total = round((float) $bills->sum('commission_amount'), 2);
            $final = round($total + $adjustments, 2);

            $settlement = BranchCommissionSettlement::create([
                'settlement_number' => 'HQ-SET-'.now()->format('YmdHis').'-'.random_int(100, 999),
                'branch_id' => $branchId,
                'total_commission' => $total,
                'adjustments' => $adjustments,
                'final_payable_amount' => $final,
                'status' => 'pending',
            ]);

            BranchCommissionBill::query()
                ->whereIn('id', $bills->pluck('id'))
                ->update([
                    'settlement_id' => $settlement->id,
                    'status' => 'processing',
                ]);

            return $settlement->load('bills');
        });
    }

    /**
     * Branch pays HQ: use branch HamroPay account; destination = company merchant_id.
     */
    public function payViaHamroPay(BranchCommissionSettlement $settlement): array
    {
        if (in_array(strtolower((string) $settlement->status), ['paid'], true)) {
            throw ValidationException::withMessages([
                'settlement' => ['Already paid to Tukaatu Express.'],
            ]);
        }

        $amount = (float) $settlement->final_payable_amount;
        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'settlement' => ['Payable amount must be greater than zero.'],
            ]);
        }

        $branchAccount = $this->accounts->branchAccount((int) $settlement->branch_id, 'hamropay');
        if (! $branchAccount) {
            throw ValidationException::withMessages([
                'gateway' => ['This branch has no HamroPay account. Branch manager must save credentials first.'],
            ]);
        }

        $companyAccount = $this->accounts->companyAccount('hamropay');
        $companyMerchantId = $companyAccount?->credential('merchant_id')
            ?: config('hamropay.merchant_id');

        if (! filled($companyMerchantId)) {
            throw ValidationException::withMessages([
                'gateway' => ['Company (superadmin) HamroPay merchant_id is not configured.'],
            ]);
        }

        $client = $this->accounts->hamroPayClientFromAccount($branchAccount);
        $paisa = (int) round($amount * 100);
        $txnId = 'HQPAY-'.$settlement->id.'-'.Str::upper(Str::random(8));

        // Pay into company wallet: platform merchant = company; session under branch creds
        $session = $client->createSession([
            'merchantTxnId' => $txnId,
            'transactionAmount' => $paisa,
            'remarks' => 'HQ commission '.$settlement->settlement_number,
        ], (string) $companyMerchantId, null);

        $sessionId = data_get($session, 'sessionId')
            ?? data_get($session, 'data.sessionId')
            ?? data_get($session, 'session_id');

        if (! $sessionId) {
            throw ValidationException::withMessages([
                'hamropay' => [data_get($session, 'message', 'HamroPay did not return a session id.')],
            ]);
        }

        $params = $client->buildCheckoutParams(
            (string) $sessionId,
            $txnId,
            $paisa,
            'HQ commission '.$settlement->settlement_number,
            (string) $companyMerchantId,
        );

        $settlement->update([
            'payment_method' => 'hamropay',
            'payment_reference' => $txnId,
            'gateway' => 'hamropay',
        ]);

        return [
            'settlement_id' => $settlement->id,
            'merchant_txn_id' => $txnId,
            'session_id' => $sessionId,
            'amount' => $amount,
            'gateway_url' => $client->getGatewayUrl(),
            'checkout' => $params,
            'payee' => 'tukaatu_express_company',
            'provider' => $session,
        ];
    }

    public function markPaid(BranchCommissionSettlement $settlement, ?int $userId = null, ?string $reference = null): BranchCommissionSettlement
    {
        return DB::transaction(function () use ($settlement, $userId, $reference) {
            $settlement->update([
                'status' => 'paid',
                'paid_at' => now(),
                'paid_by' => $userId,
                'payment_reference' => $reference ?: $settlement->payment_reference,
                'payment_method' => $settlement->payment_method ?: 'manual',
            ]);

            BranchCommissionBill::query()
                ->where('settlement_id', $settlement->id)
                ->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'paid_by' => $userId,
                    'payment_method' => $settlement->payment_method,
                    'payment_reference' => $settlement->payment_reference,
                ]);

            return $settlement->fresh('bills');
        });
    }
}
