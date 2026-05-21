<?php

namespace Tests\Unit\Ticimax;

use App\Services\Ticimax\ProductMapper;
use PHPUnit\Framework\TestCase;

class ProductMapperTest extends TestCase
{
    private ProductMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new ProductMapper();
    }

    public function test_temel_alanlar_kopyalanir(): void
    {
        $payload = $this->mapper->anaToBayiCreatePayload([
            'Barkod' => '8690000000001',
            'StokKodu' => 'KK-001',
            'UrunAdi' => 'Test Oyuncak',
            'KisaAciklama' => 'Kısa',
            'Aciklama' => 'Uzun açıklama',
            'KategoriID' => 5,
            'Marka' => 'Karavankids',
            'SatisFiyati' => 199.90,
            'KdvOrani' => 20,
            'StokAdedi' => 12,
        ]);

        $this->assertSame('8690000000001', $payload['Barkod']);
        $this->assertSame('KK-001', $payload['StokKodu']);
        $this->assertSame('Test Oyuncak', $payload['UrunAdi']);
        $this->assertSame(5, $payload['KategoriID']);
        $this->assertSame('Karavankids', $payload['Marka']);
        $this->assertSame(199.90, $payload['SatisFiyati']);
        $this->assertSame(20, $payload['KdvOrani']);
        $this->assertSame(12, $payload['StokAdedi']);
        $this->assertSame(0, $payload['UrunKartiID'], 'Yeni ürün için UrunKartiID=0 olmalı');
    }

    public function test_stok_kodu_yoksa_barkod_kullanilir(): void
    {
        $payload = $this->mapper->anaToBayiCreatePayload([
            'Barkod' => 'BARCODE1',
            'UrunAdi' => 'X',
        ]);
        $this->assertSame('BARCODE1', $payload['StokKodu']);
    }

    public function test_seo_alanlari_dolduruluyorsa_korunur(): void
    {
        $payload = $this->mapper->anaToBayiCreatePayload([
            'Barkod' => 'B1',
            'UrunAdi' => 'Ad',
            'SeoBaslik' => 'Özel SEO Başlık',
            'SeoAciklama' => 'Özel Meta',
            'SeoUrl' => 'ozel-url',
        ]);
        $this->assertSame('Özel SEO Başlık', $payload['SeoBaslik']);
        $this->assertSame('Özel Meta', $payload['SeoAciklama']);
        $this->assertSame('ozel-url', $payload['SeoUrl']);
    }

    public function test_seo_baslik_yoksa_urun_adi_kullanilir(): void
    {
        $payload = $this->mapper->anaToBayiCreatePayload([
            'Barkod' => 'B1',
            'UrunAdi' => 'Varsayılan Ad',
        ]);
        $this->assertSame('Varsayılan Ad', $payload['SeoBaslik']);
    }

    public function test_resimler_url_listesi_olur(): void
    {
        $payload = $this->mapper->anaToBayiCreatePayload([
            'Barkod' => 'B1',
            'UrunAdi' => 'X',
            'Resimler' => [
                ['ResimUrl' => 'https://cdn.karavankids.com/p/1.jpg'],
                ['Url' => 'https://cdn.karavankids.com/p/2.jpg'],
                'https://cdn.karavankids.com/p/3.jpg',
            ],
        ]);

        $this->assertCount(3, $payload['Resimler']);
        $this->assertSame('https://cdn.karavankids.com/p/1.jpg', $payload['Resimler'][0]);
        $this->assertSame('https://cdn.karavankids.com/p/2.jpg', $payload['Resimler'][1]);
        $this->assertSame('https://cdn.karavankids.com/p/3.jpg', $payload['Resimler'][2]);
    }

    public function test_bos_veya_invalid_resimler_filtrelenir(): void
    {
        $payload = $this->mapper->anaToBayiCreatePayload([
            'Barkod' => 'B1',
            'UrunAdi' => 'X',
            'Resimler' => [
                ['ResimUrl' => 'https://x/1.jpg'],
                ['BasterAlan' => 'unknown'],
                null,
            ],
        ]);
        $this->assertCount(1, $payload['Resimler']);
    }

    public function test_varyasyonlar_aktarilir(): void
    {
        $payload = $this->mapper->anaToBayiCreatePayload([
            'Barkod' => 'PARENT',
            'UrunAdi' => 'Ana Ürün',
            'Varyasyonlar' => [
                [
                    'Barkod' => 'V-RED',
                    'StokKodu' => 'KK-001-R',
                    'StokAdedi' => 5,
                    'SatisFiyati' => 199.90,
                    'Ozellikler' => [['OzellikAdi' => 'Renk', 'Deger' => 'Kırmızı']],
                    'Resimler' => ['https://x/red.jpg'],
                ],
                [
                    'Barkod' => 'V-BLUE',
                    'StokKodu' => 'KK-001-B',
                    'StokAdedi' => 3,
                    'SatisFiyati' => 199.90,
                ],
            ],
        ]);

        $this->assertCount(2, $payload['Varyasyonlar']);
        $this->assertSame('V-RED', $payload['Varyasyonlar'][0]['Barkod']);
        $this->assertSame(5, $payload['Varyasyonlar'][0]['StokAdedi']);
        $this->assertCount(1, $payload['Varyasyonlar'][0]['Resimler']);
        $this->assertSame('V-BLUE', $payload['Varyasyonlar'][1]['Barkod']);
        $this->assertSame([], $payload['Varyasyonlar'][1]['Resimler'], 'Resimler verilmediyse boş dizi olmalı');
    }

    public function test_varyasyon_listesi_yoksa_bos_dizi_doner(): void
    {
        $payload = $this->mapper->anaToBayiCreatePayload([
            'Barkod' => 'B1',
            'UrunAdi' => 'X',
        ]);
        $this->assertSame([], $payload['Varyasyonlar']);
    }

    public function test_fiyat_tipleri_floata_cast_edilir(): void
    {
        $payload = $this->mapper->anaToBayiCreatePayload([
            'Barkod' => 'B1',
            'UrunAdi' => 'X',
            'SatisFiyati' => '149.50',
            'AlisFiyati' => '100',
            'Desi' => '0.5',
        ]);
        $this->assertSame(149.50, $payload['SatisFiyati']);
        $this->assertSame(100.0, $payload['AlisFiyati']);
        $this->assertSame(0.5, $payload['Desi']);
    }

    public function test_stok_int_cast_edilir(): void
    {
        $payload = $this->mapper->anaToBayiCreatePayload([
            'Barkod' => 'B1',
            'UrunAdi' => 'X',
            'StokAdedi' => '7',
        ]);
        $this->assertSame(7, $payload['StokAdedi']);
    }

    public function test_aktif_bayrak_default_true(): void
    {
        $payload = $this->mapper->anaToBayiCreatePayload([
            'Barkod' => 'B1',
            'UrunAdi' => 'X',
        ]);
        $this->assertTrue($payload['Aktif']);
    }
}
