<?php

namespace Tests\Unit;

use App\Livewire\ApiSettings;
use PHPUnit\Framework\TestCase;

/**
 * ApiSettings::classifyConnectionError() — SOAP hata mesajını kullanıcı dostu
 * Türkçe tanıya çevirir (geçersiz yetki kodu vs. sunucuya ulaşılamadı vs. diğer).
 */
class ConnectionErrorClassifyTest extends TestCase
{
    public function test_gecersiz_yetki_kodu_tespit_edilir(): void
    {
        $msg = ApiSettings::classifyConnectionError('Ticimax call failed after 3 attempts: Hatalı Kullanıcı Kodu');
        $this->assertStringContainsString('Geçersiz yetki kodu', $msg);
    }

    public function test_sunucuya_ulasilamadi_tespit_edilir(): void
    {
        $this->assertStringContainsString('Sunucuya ulaşılamadı',
            ApiSettings::classifyConnectionError('SOAP client init failed for product: Could not connect to host'));
        $this->assertStringContainsString('Sunucuya ulaşılamadı',
            ApiSettings::classifyConnectionError('failed to load external entity "https://x/UrunServis.svc?wsdl"'));
    }

    public function test_bilinmeyen_hata_kisaltilir_ve_isaretlenir(): void
    {
        $msg = ApiSettings::classifyConnectionError('Beklenmedik bir SOAP fault oluştu');
        $this->assertStringStartsWith('❌ Hata:', $msg);
        $this->assertStringContainsString('Beklenmedik', $msg);
    }
}
