<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductMapping extends Model
{
    protected $fillable = [
        'barcode',
        'ana_product_id',
        'bayi_product_id',
        'last_synced_at',
        'last_price',
        'last_stock',
        'status',
        'last_error',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'last_price' => 'decimal:2',
        'last_stock' => 'integer',
    ];
}
