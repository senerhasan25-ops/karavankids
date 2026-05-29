<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiCredential extends Model
{
    protected $fillable = [
        'store_key',
        'endpoint_url',
        'wsdl_path_product',
        'wsdl_path_order',
        'username',
        'password',
        'is_active',
    ];

    protected $casts = [
        // username = Ticimax "Web Servis Yetki Kodu" = asıl gizli anahtar → şifreli sakla.
        'username' => 'encrypted',
        'password' => 'encrypted',
        'is_active' => 'boolean',
    ];

    public static function forStore(string $storeKey): ?self
    {
        return static::where('store_key', $storeKey)->where('is_active', true)->first();
    }
}
