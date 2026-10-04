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
}
