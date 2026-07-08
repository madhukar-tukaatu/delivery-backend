<?php

namespace Modules\Merchant\Models;

use Illuminate\Database\Eloquent\Model;

class ApiLog extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['request_payload' => 'array', 'response_payload' => 'array']; }
    public function merchant() { return $this->belongsTo(Merchant::class); }
    public function apiKey() { return $this->belongsTo(MerchantApiKey::class, 'merchant_api_key_id'); }
}
