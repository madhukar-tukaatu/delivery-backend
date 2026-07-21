<?php

namespace Modules\Branch\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchTeamPosition extends Model
{
    protected $guarded = [];

    protected $hidden = [
        'temporary_password_encrypted',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'unassigned_at' => 'datetime',
        'credentials_revealed_at' => 'datetime',
    ];

    public const STATUS_VACANT = 'vacant';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_TEMPORARILY_UNASSIGNED =
        'temporarily_unassigned';

    public const STATUS_DISABLED = 'disabled';

    public function branch(): BelongsTo
    {
        return $this->belongsTo(
            Branch::class,
            'branch_id'
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'user_id'
        );
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'assigned_by'
        );
    }
}