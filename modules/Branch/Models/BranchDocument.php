<?php

namespace Modules\Branch\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchDocument extends Model
{
    protected $fillable = [
        'branch_id',
        'document_type',
        'file_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'disk',
        'status',
        'remarks',
        'verified_by',
        'verified_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
