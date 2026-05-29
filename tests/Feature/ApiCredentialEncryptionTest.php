<?php

namespace Tests\Feature;

use App\Models\ApiCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * api_credentials.username + password şifreli saklanır (#3).
 */
class ApiCredentialEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_username_ve_password_dbde_sifreli_modelde_cozulur(): void
    {
        ApiCredential::create([
            'store_key' => 'ana',
            'endpoint_url' => 'https://example.test',
            'username' => 'GIZLI-YETKI-KODU',
            'password' => 'parola123',
            'is_active' => true,
        ]);

        // DB'deki ham değer düz metin OLMAMALI (şifreli)
        $raw = DB::table('api_credentials')->where('store_key', 'ana')->first();
        $this->assertNotSame('GIZLI-YETKI-KODU', $raw->username);
        $this->assertNotSame('parola123', $raw->password);

        // Ham değer Crypt ile çözülebilmeli (gerçekten Laravel encryption)
        $this->assertSame('GIZLI-YETKI-KODU', Crypt::decryptString($raw->username));

        // Model accessor orijinal değeri vermeli
        $cred = ApiCredential::forStore('ana');
        $this->assertSame('GIZLI-YETKI-KODU', $cred->username);
        $this->assertSame('parola123', $cred->password);
    }

    public function test_for_store_sadece_aktif_kaydi_doner(): void
    {
        ApiCredential::create([
            'store_key' => 'bayi',
            'endpoint_url' => 'https://example.test',
            'username' => 'X',
            'password' => '',
            'is_active' => false,
        ]);

        $this->assertNull(ApiCredential::forStore('bayi'));
    }

    public function test_for_store_cache_db_sorgusunu_tekrarlamaz(): void
    {
        ApiCredential::create([
            'store_key' => 'ana',
            'endpoint_url' => 'https://example.test',
            'username' => 'U',
            'password' => '',
            'is_active' => true,
        ]);

        DB::enableQueryLog();
        $first = ApiCredential::forStore('ana');
        $second = ApiCredential::forStore('ana');
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertNotNull($first);
        $this->assertSame($first, $second);   // aynı obje (cache'ten)
        $this->assertSame(1, $queryCount);     // ikinci çağrıda DB'ye gidilmedi

        // forgetCache sonrası tekrar DB'ye gitmeli
        ApiCredential::forgetCache('ana');
        DB::flushQueryLog();
        DB::enableQueryLog();
        ApiCredential::forStore('ana');
        $this->assertSame(1, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }
}
