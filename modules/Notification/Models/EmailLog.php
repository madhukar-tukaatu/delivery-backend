<?php

namespace Modules\Notification\Models;

use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['sent_at' => 'datetime']; }
}
