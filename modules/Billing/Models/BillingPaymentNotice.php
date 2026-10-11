<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Branch\Models\Branch;

/**
 * "We paid" notice from a marketplace (billing API) or a merchant (portal).
 * A notice NEVER changes paid / outstanding. The billing branch verifies it
 * (which records a payment through the payment service) or rejects it.
 * HamroPay notices (status awaiting_gateway) are verified by the provider.
 *
 * payer_type: marketplace | merchant        target_type: marketplace_bill | invoice
 * status: pending_verification | awaiting_gateway | verified | rejected | failed
 */
class BillingPaymentNotice extends Model
{
    public const OPEN_STATUSES = ['pending_verification', 'awaiting_gateway'];

    protected $guarded = [];

    protected $casts = [
        'amount' => 'float',
        'verified_amount' => 'float',
        'paid_at' => 'datetime',
        'verified_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function isPending(): bool
    {
        return (string) $this->status === 'pending_verification';
    }

    public function target(): ?Model
    {
        return $this->target_type === 'marketplace_bill'
            ? MarketplaceBill::query()->find($this->target_id)
            : Invoice::query()->find($this->target_id);
    }
}