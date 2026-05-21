<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyncJob extends Model
{
    protected $fillable = [
        'type',
        'status',
        'started_at',
        'finished_at',
        'total',
        'success_count',
        'error_count',
        'last_error',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function logs(): HasMany
    {
        return $this->hasMany(SyncLog::class, 'job_id');
    }
}
