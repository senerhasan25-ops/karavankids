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

    public function test_urun_karti_temel_alanlar_kopyalanir(): void
    {
        $payload = $this->mapper->anaToBayiCreatePayload([
            'ID' => 1001,
            'UrunAdi' => 'Test Oyuncak',
            'Aciklama' => 'Uzun açıklama',
            'OnYazi' => 'Kısa',
            'Aktif' => true,
            'AnaKategori' => 'Oyuncaklar',
            'AnaKategoriID' => 5,
            'Marka' => 'Karavankids',
            'MarkaID' => 3,
        ]);

        $this->assertSame(0, $payload['ID'], 'Yeni kayıt için ID=0 olmalı (ana ID atılır)');
        $this->assertSame('Test Oyuncak', $payload['UrunAdi']);
        $this->assertSame('Uzun açıklama', $payload['Aciklama']);
        $this->assertSame('Kısa', $payload['OnYazi']);
        $this->assertSame('Oyuncaklar', $payload['AnaKategori']);
        $this->assertSame(5, $payload['AnaKategoriID']);
        $this->assertSame('Karavankids', $payload['Marka']);
        $this->assertSame(3, $payload['MarkaID']);
        $this->assertTrue($payload['Aktif']);
    }

    public function test_seo_alanlari_dogru_isimle_aktarilir(): void
    {
        $payload = $this->mapper->anaToBayiCreatePayload([
            'UrunAdi' => 'Ad',
            'SeoSayfaBaslik' => 'Özel SEO Başlık',
            'SeoSayfaAciklama' => 'Özel Meta',
            'SeoAnahtarKelime' => 'oyuncak,test',
            'UrunSayfaAdresi' => '/oyuncak/test',
        ]);
        $this->assertSame('Özel SEO Başlık', $payload['SeoSayfaBaslik']);
        $this->assertSame('Özel Meta', $payload['SeoSayfaAciklama']);
        $this->assertSame('oyuncak,test', $payload['SeoAnahtarKelime']);
        $this->assertSame('/oyuncak/test', $payload['UrunSayfaAdresi']);
    }

    public function test_seo_baslik_yoksa_urun_adi_kullanilir(): void
    {
        $payload = $this->mapper->anaToBayiCreatePayload([
            'UrunAdi' => 'Varsayılan Ad',
        ]);
        $this->assertSame('Varsayılan Ad', $payload['SeoSayfaBaslik']);
    }

    public function test_resimler_url_listesi_olur(): void
    {
        $payload = $this->mapper->anaToBayiCreatePayload([
            'UrunAdi' => 'X',
            'Resimler' => [
                'https://cdn.karavankids.com/p/1.jpg',
                'https://cdn.karavankids.com/p/2.jpg',
            ],
        ]);

        $this->assertCount(2, $payload['Resimler']);
        $this->assertSame('https://cdn.karavankids.com/p/1.jpg', $payload['Resimler'][0]);
    }

    public function test_soap_wrapper_yapisindaki_resimler_acilir(): void
    {
        // SOAP'tan dönen ArrayOfstring şekli: ['string' => [...]]
        $payload = $this->mapper->anaToBayiCreatePayload([
            'UrunAdi' => 'X',
            'Resimler' => ['string' => ['https://x/1.jpg', 'https://x/2.jpg']],
        ]);
        $this->assertCount(2, $payload['Resimler']);
    }

    public function test_varyasyonlar_aktarilir_ve_ID_sifirlanir(): void
    {
        $payload = $this->mapper->anaToBayiCreatePayload([
            'UrunAdi' => 'Ana Ürün',
            'Varyasyonlar' => [
                [
                    'ID' => 555,
                    'UrunKartiID' => 1001,
                    'Barkod' => 'V-RED',
                    'StokKodu' => 'KK-001-R',
                    'StokAdedi' => 5,
                    'SatisFiyati' => 199.90,
                    'KdvOrani' => 20,
                    'KdvDahil' => true,
                    'Ozellikler' => [['Tanim' => 'Renk', 'Deger' => 'Kırmızı']],
                ],
                [
                    'ID' => 556,
                    'Barkod' => 'V-BLUE',
                    'StokKodu' => 'KK-001-B',
                    'StokAdedi' => 3,
                    'SatisFiyati' => 199.90,
                ],
            ],
        ]);

        $this->assertCount(2, $payload['Varyasyonlar']);

        $v1 = $payload['Varyasyonlar'][0];
        $this->assertSame(0, $v1['ID'], 'Varyasyon ID sıfırlanmalı');
        $this->assertSame(0, $v1['UrunKartiID'], 'Varyasyonun UrunKartiID de sıfırlanmalı');
        $this->assertSame('V-RED', $v1['Barkod']);
        $this->assertSame(5.0, $v1['StokAdedi']);
        $this->assertSame(199.90, $v1['SatisFiyati']);
        $this->assertSame(20.0, $v1['KdvOrani']);
        $this->assertTrue($v1['KdvDahil']);
        $this->assertCount(1, $v1['Ozellikler']);
        $this->assertSame('Kırmızı', $v1['Ozellikler'][0]['Deger']);
    }

    public function test_varyasyon_listesi_bos_ama_barkod_varsa_fallback_olusturulur(): void
    {
        $payload = $this->mapper->anaToBayiCreatePayload([
            'UrunAdi' => 'Tekil Ürün',
            'Barkod' => '8690000000001',
            'StokKodu' => 'KK-X',
            'StokAdedi' => 12,
            'SatisFiyati' => 99.90,
            'KdvOrani' => 20,
        ]);

        $this->assertCount(1, $payload['Varyasyonlar'], 'Üst seviye barkod varsa fallback varyasyon üretilmeli');
        $v = $payload['Varyasyonlar'][0];
        $this->assertSame('8690000000001', $v['Barkod']);
        $this->assertSame(12.0, $v['StokAdedi']);
        $this->assertSame(99.90, $v['SatisFiyati']);
    }

    public function test_hicbir_barkod_yoksa_varyasyon_listesi_bos_kalir(): void
    {
        $payload = $this->mapper->anaToBayiCreatePayload([
            'UrunAdi' => 'X',
        ]);
        $this->assertSame([], $payload['Varyasyonlar']);
    }

    public function test_soap_wrapper_yapisindaki_varyasyonlar_acilir(): void
    {
        $payload = $this->mapper->anaToBayiCreatePayload([
            'UrunAdi' => 'X',
            'Varyasyonlar' => ['Varyasyon' => [
                ['Barkod' => 'V1', 'SatisFiyati' => 10],
                ['Barkod' => 'V2', 'SatisFiyati' => 20],
            ]],
        ]);
        $this->assertCount(2, $payload['Varyasyonlar']);
        $this->assertSame('V1', $payload['Varyasyonlar'][0]['Barkod']);
    }

    public function test_kategoriler_int_listesi_acilir(): void
    {
        $payload = $this->mapper->anaToBayiCreatePayload([
            'UrunAdi' => 'X',
            'Kategoriler' => ['int' => [5, 7, 11]],
        ]);
        $this->assertSame([5, 7, 11], $payload['Kategoriler']);
    }

    public function test_aktif_bayrak_default_true(): void
    {
        $payload = $this->mapper->anaToBayiCreatePayload(['UrunAdi' => 'X']);
        $this->assertTrue($payload['Aktif']);
    }

    public function test_deactivate_payload(): void
    {
        $payload = $this->mapper->deactivatePayload();
        $this->assertSame(['Aktif' => false], $payload);
    }
}
