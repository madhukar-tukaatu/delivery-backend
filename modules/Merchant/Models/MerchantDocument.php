<?php

namespace Modules\Merchant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class MerchantDocument extends Model
{
    protected $fillable = [
        'merchant_id',
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

    protected $appends = [
        'download_url',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'merchant_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'verified_by');
    }

    public function getDownloadUrlAttribute(): ?string
    {
        if (!$this->id) {
            return null;
        }

        return url("/api/v1/merchant/documents/{$this->id}/download");
    }

    public function existsInStorage(): bool
    {
        return $this->file_path
            && Storage::disk($this->disk ?: 'local')->exists($this->file_path);
    }
}