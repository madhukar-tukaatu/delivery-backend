<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Branch\Models\Branch;
use Modules\Setting\Models\Marketplace;

/**
 * A delivery-charge bill one branch sends to one marketplace (Tukaatu, FCA, ...)
 * for marketplace free-delivery shipments. Lines are the company invoices with
 * invoices.marketplace_bill_id = this id.
 *
 * Status: draft -> sent -> partially_paid -> paid. Payments are rows in
 * marketplace_bill_payments; paid_amount / outstanding_amount are recomputed
 * from them (MarketplaceBillPaymentService). Reversing payments ("Reverse
 * payment" / "Report not received") moves the bill back to partially_paid, or
 * to reopened when nothing is left paid. draft/sent/reopened bills with no
 * recorded payment can be cancelled, which releases the invoices.
 */
class MarketplaceBill extends Model
{
    /** Not fully paid and not cancelled: invoices stay locked to the bill. */
    public const OPEN_STATUSES = ['draft', 'sent', 'partially_paid', 'reopened'];

    /** Statuses that accept a payment. */
    public const PAYABLE_STATUSES = ['sent', 'partially_paid', 'reopened'];

    protected $guarded = [];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'total_amount' => 'float',
        'paid_amount' => 'float',
        'outstanding_amount' => 'float',
        'hamropay_sessions' => 'array',
        'invoice_count' => 'integer',
        'send_count' => 'integer',
        'sent_at' => 'datetime',
        'paid_at' => 'datetime',
        'reopened_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'payment_history' => 'array',
    ];

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'marketplace_bill_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(MarketplaceBillPayment::class, 'marketplace_bill_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function marketplace(): BelongsTo
    {
        return $this->belongsTo(Marketplace::class);
    }

    public function isOpen(): bool
    {
        return in_array((string) $this->status, self::OPEN_STATUSES, true);
    }

    public function isPayable(): bool
    {
        return in_array((string) $this->status, self::PAYABLE_STATUSES, true);
    }

    public function paidSoFar(): float
    {
        return round((float) ($this->paid_amount ?? 0), 2);
    }

    /** Outstanding = total - paid (0 for cancelled bills). */
    public function outstanding(): float
    {
        if ((string) $this->status === 'cancelled') {
            return 0.0;
        }

        return max(0.0, round((float) $this->total_amount - $this->paidSoFar(), 2));
    }

    public function isPaid(): bool
    {
        return (string) $this->status === 'paid';
    }
}
