<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bir VARYASYON eşleştirmesi — kaynak (ana) ↔ hedef (bayi).
 *
 * stok_kodu hem kaynak hem hedefte ortak iş kimliğidir; primary lookup
 * anahtarımız bu. Her iki taraf için UrunKartiID ve VaryasyonID ayrı tutulur
 * (iki sistemde farklı ID'ler atanıyor). Bu sayede senkronizasyon yaparken
 * Ticimax'a "var mı?" diye sormaya gerek kalmaz; lokal lookup yeter.
 */
class ProductMapping extends Model
{
    protected $fillable = [
        'barcode',
        'stok_kodu',
        'ana_product_id',
        'ana_variant_id',
        'bayi_product_id',
        'bayi_variant_id',
        'tedarikci_kodu',
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
