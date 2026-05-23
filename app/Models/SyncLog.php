<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncLog extends Model
{
    protected $fillable = [
        'job_id',
        'barcode',
        'stok_kodu',
        'urun_adi',
        'ana_id',
        'bayi_id',
        'action',
        'direction',
        'status',
        'message',
        'raw_request',
        'raw_response',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(SyncJob::class, 'job_id');
    }
}
