<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment on a merchant delivery-charge invoice (partial payments allowed).
 * Recorded only by the billing branch / HQ (directly or by verifying a merchant
 * payment notice) or by a provider-confirmed HamroPay transaction.
 *
 * source: branch | admin | notice_verified | hamropay
 */
class MerchantInvoicePayment extends Model
{
    public const SOURCES = ['branch', 'admin', 'notice_verified', 'hamropay'];

    protected $guarded = [];

    protected $casts = [
        'amount' => 'float',
        'paid_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isRecorded(): bool
    {
        return (string) $this->status === 'recorded';
    }

    /** invoices.paid_by_type value for this payment. */
    public function payerType(): string
    {
        return match ((string) $this->source) {
            'hamropay' => 'hamropay',
            'admin' => 'admin',
            default => (string) $this->recorded_by_type === 'admin' ? 'admin' : 'branch',
        };
    }
}