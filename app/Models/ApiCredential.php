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

    /**
     * Process-içi cache (#10). forStore() bir job süresince TicimaxClient her
     * kurulduğunda çağrılıyor; aynı store için tekrar tekrar DB'ye gitmeyelim.
     * Cache process'e özgü olduğundan (her job/web isteği ayrı process) çapraz
     * bayatlama riski yok; aynı process içinde kayıt güncellenirse forgetCache()
     * çağrılır (ApiSettings::save).
     *
     * @var array<string, self|null>
     */
    protected static array $storeCache = [];

    public static function forStore(string $storeKey): ?self
    {
        if (array_key_exists($storeKey, static::$storeCache)) {
            return static::$storeCache[$storeKey];
        }

        return static::$storeCache[$storeKey] = static::where('store_key', $storeKey)
            ->where('is_active', true)
            ->first();
    }

    /** Process-içi forStore cache'ini temizle (credential güncellendiğinde). */
    public static function forgetCache(?string $storeKey = null): void
    {
        if ($storeKey === null) {
            static::$storeCache = [];

            return;
        }

        unset(static::$storeCache[$storeKey]);
    }
}
