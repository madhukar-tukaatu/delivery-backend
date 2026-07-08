<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $guarded = [];

    protected $casts = [
        'invoice_date' => 'date',
    ];

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }
}
