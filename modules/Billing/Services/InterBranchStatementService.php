<?php

namespace Modules\Billing\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Models\InterBranchStatement;
use Modules\Billing\Models\InterBranchStatementLine;
use Modules\Billing\Models\ShipmentBranchShare;

/**
 * Collecting branch -> other branch statements for transfer delivery shares.
 * One statement per (collecting branch, receiving branch, period).
 */
class InterBranchStatementService
{
    public function __construct(
        private BranchShareService $shares,
        private PaymentGatewayAccountService $accounts,
    ) {
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function periodBounds(string $period = 'weekly', ?string $from = null, ?string $to = null): array
    {
        $tz = config('app.timezone');
        if ($from || $to) {
            $start = CarbonImmutable::parse($from ?? $to, $tz)->startOfDay();
            $end = CarbonImmutable::parse($to ?? $from, $tz)->startOfDay();

            return $start->lte($end) ? [$start, $end] : [$end, $start];
        }

        $yesterday = CarbonImmutable::now($tz)->subDay()->startOfDay();
        if ($period === 'daily') {
            return [$yesterday, $yesterday];
        }

        return [$yesterday->subDays(6), $yesterday];
    }

    /**
     * Put every eligible share delivered up to period_end on a statement.
     * Shares that became eligible late (invoice paid after an earlier run)
     * are picked up by the next run, so nothing is stranded.
     *
     * @return array{statements: Collection, lines: int, kept_by_collector: int}
     */
    public function generate(CarbonImmutable $start, CarbonImmutable $end, ?array $onlyBranchIds = null, ?int $userId = null): array
    {
        return DB::transaction(function () use ($start, $end, $onlyBranchIds) {
            $rows = $this->shares->eligibleQuery()
                ->where('shipments.delivered_at', '<=', $end->endOfDay())
                ->lockForUpdate()
                ->get();

            $kept = 0;
            $lineCount = 0;
            $touched = collect();

            foreach ($rows as $share) {
                $from = (int) $share->collecting_branch_id;
                $to = (int) $share->branch_id;

                if ($onlyBranchIds !== null && ! in_array($from, $onlyBranchIds, true) && ! in_array($to, $onlyBranchIds, true)) {
                    continue;
                }

                if ($from === $to) {
                    // The collecting branch keeps its own allocation.
                    $share->forceFill(['status' => 'settled'])->save();
                    $kept++;

                    continue;
                }

                $statement = InterBranchStatement::query()
                    ->where('from_branch_id', $from)
                    ->where('to_branch_id', $to)
                    ->whereDate('period_start', $start->toDateString())
                    ->whereDate('period_end', $end->toDateString())
                    ->where('status', 'draft')
                    ->lockForUpdate()
                    ->first();

                if (! $statement) {
                    $statement = InterBranchStatement::create([
                        'statement_number' => 'IBS-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                        'from_branch_id' => $from,
                        'to_branch_id' => $to,
                        'period_start' => $start->toDateString(),
                        'period_end' => $end->toDateString(),
                        'total_amount' => 0,
                        'status' => 'draft',
                    ]);
                }

                InterBranchStatementLine::create([
                    'statement_id' => $statement->id,
                    'shipment_branch_share_id' => $share->id,
                    'shipment_id' => $share->shipment_id,
                    'role' => $share->role,
                    'share_amount' => $share->share_amount,
                    'transport_amount' => $share->transport_amount,
                    'extra_distance_amount' => $share->extra_distance_amount,
                    'amount' => $share->allocation_amount,
                ]);

                $share->forceFill(['statement_id' => $statement->id, 'status' => 'on_statement'])->save();
                $lineCount++;
                $touched->put($statement->id, $statement);
            }

            foreach ($touched as $statement) {
                $this->recalc($statement);
            }

            return [
                'statements' => $touched->values()->map->fresh(),
                'lines' => $lineCount,
                'kept_by_collector' => $kept,
            ];
        });
    }

    public function recalc(InterBranchStatement $statement): void
    {
        $statement->forceFill([
            'total_amount' => round((float) $statement->lines()->sum('amount'), 2),
            'line_count' => $statement->lines()->count(),
        ])->save();
    }

    public function issue(InterBranchStatement $statement, ?int $userId): InterBranchStatement
    {
        if ($statement->status !== 'draft') {
            throw ValidationException::withMessages(['statement' => ['Only a draft statement can be issued.']]);
        }
        $statement->forceFill(['status' => 'issued', 'issued_at' => now(), 'issued_by' => $userId])->save();

        return $statement->fresh();
    }

    /**
     * Paying branch (from) pays the receiving branch (to) through HamroPay:
     * session under the payer branch account, destination = the receiving
     * branch's HamroPay merchant_id. Same pattern as HQ commission payment.
     */
    public function payViaHamroPay(InterBranchStatement $statement): array
    {
        if (in_array($statement->status, ['paid', 'received'], true)) {
            throw ValidationException::withMessages(['statement' => ['This statement is already paid.']]);
        }
        $amount = (float) $statement->total_amount;
        if ($amount <= 0) {
            throw ValidationException::withMessages(['statement' => ['Statement amount must be greater than zero.']]);
        }

        $payer = $this->accounts->branchAccount((int) $statement->from_branch_id, 'hamropay');
        if (! $payer) {
            throw ValidationException::withMessages(['gateway' => ['The paying branch has no HamroPay account. Save it under Payment gateways.']]);
        }
        $receiver = $this->accounts->branchAccount((int) $statement->to_branch_id, 'hamropay');
        $receiverMerchantId = $receiver?->credential('merchant_id');
        if (! filled($receiverMerchantId)) {
            throw ValidationException::withMessages(['gateway' => ['The receiving branch has no HamroPay merchant_id. Save it under Payment gateways.']]);
        }

        if ($statement->status === 'draft') {
            $statement->forceFill(['status' => 'issued', 'issued_at' => now()])->save();
        }

        $client = $this->accounts->hamroPayClientFromAccount($payer);
        $paisa = (int) round($amount * 100);
        $txnId = 'IBSPAY-'.$statement->id.'-'.Str::upper(Str::random(8));
        $remarks = 'Branch shares '.$statement->statement_number;

        $session = $client->createSession([
            'merchantTxnId' => $txnId,
            'transactionAmount' => $paisa,
            'remarks' => $remarks,
        ], (string) $receiverMerchantId, null);

        $sessionId = data_get($session, 'sessionId')
            ?? data_get($session, 'data.sessionId')
            ?? data_get($session, 'session_id');

        if (! $sessionId) {
            Log::warning('hamropay.inter_branch_session_failed', ['statement_id' => $statement->id, 'response' => $session]);
            throw ValidationException::withMessages([
                'hamropay' => [data_get($session, 'message', 'HamroPay did not return a session id.')],
            ]);
        }

        $params = $client->buildCheckoutParams((string) $sessionId, $txnId, $paisa, $remarks, (string) $receiverMerchantId);

        $statement->forceFill(['payment_method' => 'hamropay', 'payment_reference' => $txnId])->save();

        return [
            'statement_id' => $statement->id,
            'merchant_txn_id' => $txnId,
            'session_id' => $sessionId,
            'amount' => $amount,
            'gateway_url' => $client->getGatewayUrl(),
            'checkout' => $params,
            'provider' => $session,
        ];
    }

    public function markPaid(InterBranchStatement $statement, ?int $userId, ?string $reference = null): InterBranchStatement
    {
        if (in_array($statement->status, ['paid', 'received'], true)) {
            return $statement;
        }
        $statement->forceFill([
            'status' => 'paid',
            'issued_at' => $statement->issued_at ?? now(),
            'paid_at' => now(),
            'paid_by' => $userId,
            'payment_reference' => $reference ?: $statement->payment_reference,
            'payment_method' => $statement->payment_method ?: 'manual',
        ])->save();

        return $statement->fresh();
    }

    public function markReceived(InterBranchStatement $statement, ?int $userId): InterBranchStatement
    {
        if ($statement->status === 'received') {
            return $statement;
        }
        if (! in_array($statement->status, ['issued', 'paid'], true)) {
            throw ValidationException::withMessages(['statement' => ['Issue or pay the statement before marking it received.']]);
        }

        return DB::transaction(function () use ($statement, $userId) {
            $statement->forceFill([
                'status' => 'received',
                'paid_at' => $statement->paid_at ?? now(),
                'received_at' => now(),
                'received_by' => $userId,
            ])->save();

            ShipmentBranchShare::query()
                ->where('statement_id', $statement->id)
                ->update(['status' => 'settled', 'updated_at' => now()]);

            return $statement->fresh();
        });
    }

    /** @return list<string> */
    public function branchEmails(int $branchId): array
    {
        $branch = DB::table('branches')->where('id', $branchId)->first();
        if (! $branch) {
            return [];
        }
        $emails = [];
        if ($branch->manager_user_id) {
            $emails[] = DB::table('users')->where('id', $branch->manager_user_id)->value('email');
        }
        $emails[] = $branch->email ?? null;

        return array_values(array_unique(array_filter($emails, fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
    }

    /** @return array{to: list<string>, sent: bool} */
    public function email(InterBranchStatement $statement): array
    {
        $statement->loadMissing(['fromBranch', 'toBranch', 'lines.shipment']);
        $to = array_values(array_unique(array_merge(
            $this->branchEmails((int) $statement->from_branch_id),
            $this->branchEmails((int) $statement->to_branch_id),
        )));

        if ($to === []) {
            throw ValidationException::withMessages(['email' => ['Neither branch has a manager or branch email.']]);
        }

        $subject = 'Inter-branch statement '.$statement->statement_number;
        $body = $this->emailBody($statement);

        Mail::raw($body, function ($message) use ($to, $subject) {
            $message->to($to)->subject($subject);
        });

        $statement->forceFill(['emailed_at' => now()])->save();

        return ['to' => $to, 'sent' => true];
    }

    public function emailBody(InterBranchStatement $statement): string
    {
        $from = $statement->fromBranch?->name ?? ('Branch #'.$statement->from_branch_id);
        $to = $statement->toBranch?->name ?? ('Branch #'.$statement->to_branch_id);
        $lines = [];
        $lines[] = "Inter-branch statement {$statement->statement_number}";
        $lines[] = "Period: {$statement->period_start?->toDateString()} to {$statement->period_end?->toDateString()}";
        $lines[] = "{$from} owes {$to}: Rs ".number_format((float) $statement->total_amount, 2);
        $lines[] = 'Status: '.$statement->status;
        $lines[] = '';
        $lines[] = 'Shipment | Role | Share | Transport | Extra km | Amount';
        foreach ($statement->lines as $line) {
            $lines[] = implode(' | ', [
                $line->shipment?->tracking_number ?? ('#'.$line->shipment_id),
                $line->role,
                number_format((float) $line->share_amount, 2),
                number_format((float) $line->transport_amount, 2),
                number_format((float) $line->extra_distance_amount, 2),
                number_format((float) $line->amount, 2),
            ]);
        }
        $lines[] = '';
        $lines[] = 'HQ commission is billed separately to each branch on its own allocation.';

        return implode("\n", $lines);
    }
}
