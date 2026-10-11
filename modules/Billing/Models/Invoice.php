<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Merchant\Models\Merchant;
use Modules\Shipment\Models\Shipment;

class Invoice extends Model
{
    protected $guarded = [];

    protected $casts = [
        'invoice_date' => 'date',
        'sent_at' => 'datetime',
        'manual_checked_at' => 'datetime',
        'paid_at' => 'datetime',
        'reopened_at' => 'datetime',
        'hamropay_sessions' => 'array',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /** Frozen at creation for company (marketplace free-delivery) invoices. */
    public function marketplace(): BelongsTo
    {
        return $this->belongsTo(\Modules\Setting\Models\Marketplace::class);
    }

    /** The branch bill this company invoice was sent on, if any. */
    public function marketplaceBill(): BelongsTo
    {
        return $this->belongsTo(MarketplaceBill::class, 'marketplace_bill_id');
    }

    /** Store bill payment ledger (partial payments, reversals). */
    public function payments(): HasMany
    {
        return $this->hasMany(MerchantInvoicePayment::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(\Modules\Branch\Models\Branch::class);
    }
}
