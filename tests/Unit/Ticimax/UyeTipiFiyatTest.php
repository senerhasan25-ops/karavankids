<?php

namespace Tests\Unit\Ticimax;

use App\Services\Ticimax\ProductService;
use PHPUnit\Framework\TestCase;

/**
 * ProductService::calculateUyeTipiFiyatlari() — satış fiyatından üye tipi
 * fiyatlarını otomatik hesaplama (iskonto formülü).
 *
 *   UyeTipiFiyat1 = %35 iskonto → ×0.65
 *   UyeTipiFiyat2 = %30 iskonto → ×0.70
 *   UyeTipiFiyat3 = %40 iskonto → ×0.60
 *   UyeTipiFiyat4 = %25 iskonto → ×0.75
 *   UyeTipiFiyat5 = %20 iskonto → ×0.80
 */
class UyeTipiFiyatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Statik iskonto cache başka testten kirlenmesin → varsayılanlarla test et.
        ProductService::resetUyeTipiIskontoCache();
    }

    public function test_yuvarlak_fiyat_dogru_iskontolanir(): void
    {
        $f = ProductService::calculateUyeTipiFiyatlari(100.0);

        $this->assertSame(65.0, $f['UyeTipiFiyat1']);
        $this->assertSame(70.0, $f['UyeTipiFiyat2']);
        $this->assertSame(60.0, $f['UyeTipiFiyat3']);
        $this->assertSame(75.0, $f['UyeTipiFiyat4']);
        $this->assertSame(80.0, $f['UyeTipiFiyat5']);
    }

    public function test_kusurat_iki_basamaga_yuvarlanir(): void
    {
        $f = ProductService::calculateUyeTipiFiyatlari(149.99);

        // 149.99 × 0.65 = 97.4935 → 97.49
        $this->assertSame(97.49, $f['UyeTipiFiyat1']);
        // 149.99 × 0.60 = 89.994 → 89.99
        $this->assertSame(89.99, $f['UyeTipiFiyat3']);
    }

    public function test_sifir_fiyat_sifir_doner(): void
    {
        $f = ProductService::calculateUyeTipiFiyatlari(0.0);

        $this->assertSame(0.0, $f['UyeTipiFiyat1']);
        $this->assertSame(0.0, $f['UyeTipiFiyat5']);
    }

    public function test_tum_bes_uye_tipi_anahtari_doner(): void
    {
        $f = ProductService::calculateUyeTipiFiyatlari(50.0);

        $this->assertEqualsCanonicalizing(
            ['UyeTipiFiyat1', 'UyeTipiFiyat2', 'UyeTipiFiyat3', 'UyeTipiFiyat4', 'UyeTipiFiyat5'],
            array_keys($f)
        );
    }
}
