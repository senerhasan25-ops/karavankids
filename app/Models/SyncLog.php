<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncLog extends Model
{
    protected $fillable = [
        'job_id',
        'barcode',
        'action',
        'direction',
        'status',
        'message',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(SyncJob::class, 'job_id');
    }
}
