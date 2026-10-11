<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Partial payments for marketplace bills.
 *
 *  - marketplace_bill_payments: one row per payment (marketplace API, branch,
 *    HQ admin or HamroPay). Reversed payments stay in the table (status=reversed).
 *  - marketplace_bills.paid_amount  = SUM(recorded payments)
 *    marketplace_bills.outstanding_amount = total_amount - paid_amount
 *    status: sent -> partially_paid -> paid (reopened / cancelled unchanged).
 *  - marketplace_bills.hamropay_sessions: MBPAY- checkouts started with their amount.
 *
 * Backfill: existing bills get paid_amount from their current paid fields and
 * outstanding = total - paid; a paid bill without payment rows gets one
 * "admin" payment row built from its legacy paid_* columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketplace_bill_payments')) {
            Schema::create('marketplace_bill_payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('marketplace_bill_id')->index();
                $table->decimal('amount', 14, 2);
                $table->string('method', 64)->nullable();
                $table->string('reference', 191)->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->string('note', 500)->nullable();
                // marketplace_api | branch | admin | hamropay
                $table->string('source', 20)->index();
                // user | marketplace | system
                $table->string('recorded_by_type', 20)->nullable();
                $table->unsignedBigInteger('recorded_by')->nullable();
                // Idempotency key (namespaced, e.g. "mp:4:<X-Tukaatu-Request-Id>", "hamropay:MBPAY-...").
                $table->string('request_id', 191)->nullable()->unique();
                // recorded | reversed
                $table->string('status', 16)->default('recorded')->index();
                $table->timestamp('reversed_at')->nullable();
                $table->unsignedBigInteger('reversed_by')->nullable();
                $table->string('reverse_reason', 500)->nullable();
                $table->timestamps();

                $table->index(['marketplace_bill_id', 'status'], 'mbp_bill_status_index');
            });
        }

        Schema::table('marketplace_bills', function (Blueprint $table) {
            if (! Schema::hasColumn('marketplace_bills', 'paid_amount')) {
                $table->decimal('paid_amount', 14, 2)->nullable();
            }
            if (! Schema::hasColumn('marketplace_bills', 'outstanding_amount')) {
                $table->decimal('outstanding_amount', 14, 2)->default(0)->after('paid_amount');
            }
            if (! Schema::hasColumn('marketplace_bills', 'hamropay_sessions')) {
                $table->json('hamropay_sessions')->nullable()->after('hamropay_txn_id');
            }
        });

        // Backfill (only rows that exist today; idempotent).
        DB::table('marketplace_bills')->orderBy('id')->chunkById(200, function ($bills) {
            foreach ($bills as $b) {
                $total = round((float) $b->total_amount, 2);
                $paid = (string) $b->status === 'paid'
                    ? round((float) ($b->paid_amount ?? $total), 2)
                    : 0.0;

                $hasPayments = DB::table('marketplace_bill_payments')->where('marketplace_bill_id', $b->id)->exists();
                if ($paid > 0 && ! $hasPayments) {
                    DB::table('marketplace_bill_payments')->insert([
                        'marketplace_bill_id' => $b->id,
                        'amount' => $paid,
                        'method' => $b->payment_method,
                        'reference' => $b->payment_reference,
                        'paid_at' => $b->paid_at,
                        'note' => 'Backfilled from the bill when partial payments were introduced.',
                        'source' => match ((string) $b->paid_by_type) {
                            'marketplace' => 'marketplace_api',
                            'hamropay' => 'hamropay',
                            'branch' => 'branch',
                            default => 'admin',
                        },
                        'recorded_by_type' => (string) $b->paid_by_type === 'marketplace' ? 'marketplace' : ($b->paid_by ? 'user' : 'system'),
                        'recorded_by' => $b->paid_by,
                        'request_id' => $b->payment_request_id ? 'legacy:'.$b->id.':'.$b->payment_request_id : null,
                        'status' => 'recorded',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } elseif ($hasPayments) {
                    $paid = round((float) DB::table('marketplace_bill_payments')
                        ->where('marketplace_bill_id', $b->id)->where('status', 'recorded')->sum('amount'), 2);
                }

                DB::table('marketplace_bills')->where('id', $b->id)->update([
                    'paid_amount' => $paid,
                    'outstanding_amount' => in_array((string) $b->status, ['cancelled'], true) ? 0 : max(0, round($total - $paid, 2)),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_bills', function (Blueprint $table) {
            foreach (['outstanding_amount', 'hamropay_sessions'] as $col) {
                if (Schema::hasColumn('marketplace_bills', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::dropIfExists('marketplace_bill_payments');
    }
};
