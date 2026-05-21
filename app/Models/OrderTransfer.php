<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderTransfer extends Model
{
    protected $fillable = [
        'bayi_order_id',
        'ana_order_id',
        'status',
        'retry_count',
        'last_error',
        'payload_snapshot',
        'transferred_at',
    ];

    protected $casts = [
        'payload_snapshot' => 'array',
        'transferred_at' => 'datetime',
    ];
}
