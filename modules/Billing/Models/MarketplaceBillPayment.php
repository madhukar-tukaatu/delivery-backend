<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment against a marketplace bill. A bill can be paid in parts
 * (e.g. Rs 50,000 now on a Rs 129,999 bill). Reversed payments are kept
 * (status=reversed) so the history is complete.
 *
 * source: marketplace_api (legacy, before notices) | branch | admin | hamropay | notice_verified
 * (notice_verified = a branch manager verified a marketplace payment notice).
 */
class MarketplaceBillPayment extends Model
{
    public const SOURCES = ['marketplace_api', 'branch', 'admin', 'hamropay', 'notice_verified'];

    protected $guarded = [];

    protected $casts = [
        'amount' => 'float',
        'paid_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(MarketplaceBill::class, 'marketplace_bill_id');
    }

    public function isRecorded(): bool
    {
        return (string) $this->status === 'recorded';
    }

    /** invoices.paid_by_type / marketplace_bills.paid_by_type value for this source. */
    public function payerType(): string
    {
        return match ((string) $this->source) {
            'marketplace_api' => 'marketplace',
            'hamropay' => 'hamropay',
            'branch' => 'branch',
            'notice_verified' => (string) $this->recorded_by_type === 'admin' ? 'admin' : 'branch',
            default => 'admin',
        };
    }
}
