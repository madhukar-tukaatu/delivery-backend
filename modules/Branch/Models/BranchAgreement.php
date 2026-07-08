<?php

namespace Modules\Branch\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchAgreement extends Model
{
    protected $fillable = [
        'branch_id',
        'agreement_type',
        'file_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'disk',
        'status',
        'signed_at',
        'expires_at',
        'remarks',
        'uploaded_by',
    ];

    protected $casts = [
        'signed_at' => 'date',
        'expires_at' => 'date',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
