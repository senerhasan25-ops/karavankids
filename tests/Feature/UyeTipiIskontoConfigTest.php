<?php

namespace Tests\Feature;

use App\Models\SyncSetting;
use App\Services\Ticimax\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Üye tipi iskonto oranları panelden ayarlanabilir (sync_settings) ve
 * calculateUyeTipiFiyatlari() bu oranları kullanır.
 */
class UyeTipiIskontoConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_ayar_yoksa_varsayilan_oranlar_kullanilir(): void
    {
        ProductService::resetUyeTipiIskontoCache();
        $f = ProductService::calculateUyeTipiFiyatlari(100.0);

        $this->assertSame(65.0, $f['UyeTipiFiyat1']); // %35
        $this->assertSame(70.0, $f['UyeTipiFiyat2']); // %30
        $this->assertSame(80.0, $f['UyeTipiFiyat5']); // %20
    }

    public function test_panelden_ayarlanan_oranlar_kullanilir(): void
    {
        SyncSetting::put(
            ProductService::UYE_TIPI_ISKONTO_KEY,
            json_encode([1 => 50, 2 => 10, 3 => 0, 4 => 25, 5 => 20])
        );
        ProductService::resetUyeTipiIskontoCache();

        $f = ProductService::calculateUyeTipiFiyatlari(100.0);

        $this->assertSame(50.0, $f['UyeTipiFiyat1']);  // 100 × (1−0.50)
        $this->assertSame(90.0, $f['UyeTipiFiyat2']);  // 100 × (1−0.10)
        $this->assertSame(100.0, $f['UyeTipiFiyat3']); // 100 × (1−0)
    }

    public function test_oranlar_0_100_arasi_sinirlanir(): void
    {
        SyncSetting::put(
            ProductService::UYE_TIPI_ISKONTO_KEY,
            json_encode([1 => 150, 2 => -10, 3 => 40, 4 => 25, 5 => 20])
        );
        ProductService::resetUyeTipiIskontoCache();

        $o = ProductService::uyeTipiIskontoOranlari();

        $this->assertSame(100.0, $o[1]); // 150 → 100
        $this->assertSame(0.0, $o[2]);   // -10 → 0
        $this->assertSame(40.0, $o[3]);  // dokunulmaz
    }
}
