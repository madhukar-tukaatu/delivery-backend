<?php

namespace Modules\Notification\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['payload' => 'array', 'sent_at' => 'datetime']; }
}
