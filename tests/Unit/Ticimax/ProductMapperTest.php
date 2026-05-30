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
        $this->mapper = new ProductMapper;
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
        // Kategori ID'leri mağazalar arası farklı; mapper her zaman 0 gönderir.
        $this->assertSame(0, $payload['AnaKategoriID']);
        $this->assertSame('Karavankids', $payload['Marka']);
        // MarkaID brand resolver olmadan 0; resolver ile bayi'nin ID'sine çevrilir.
        $this->assertSame(0, $payload['MarkaID']);
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
        // UrunSayfaAdresi artık her zaman boş — Ticimax kendi üretsin (slug çakışmalarını önlemek için)
        $this->assertSame('', $payload['UrunSayfaAdresi']);
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

    public function test_varyasyonlar_aktarilir_ve_i_d_sifirlanir(): void
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

    public function test_kategoriler_her_zaman_bos_dizi_olur(): void
    {
        // Kategori ID'leri mağazalar arası farklı olduğundan mapper her zaman boş dizi gönderir.
        $payload = $this->mapper->anaToBayiCreatePayload([
            'UrunAdi' => 'X',
            'Kategoriler' => ['int' => [5, 7, 11]],
        ]);
        $this->assertSame([], $payload['Kategoriler']);
    }

    public function test_brand_resolver_kullanilirsa_marka_i_d_doldurulur(): void
    {
        $this->mapper->setBrandResolver(fn (string $name) => $name === 'Karavankids' ? 42 : 0);
        $payload = $this->mapper->anaToBayiCreatePayload([
            'UrunAdi' => 'X',
            'Marka' => 'Karavankids',
        ]);
        $this->assertSame(42, $payload['MarkaID']);
        $this->assertSame('Karavankids', $payload['Marka']);
    }

    public function test_supplier_resolver_ana_id_alir_bayi_id_doner(): void
    {
        $this->mapper->setSupplierResolver(fn (int $anaId) => $anaId === 5 ? 17 : 0);
        $payload = $this->mapper->anaToBayiCreatePayload([
            'UrunAdi' => 'X',
            'TedarikciID' => 5,
        ]);
        $this->assertSame(17, $payload['TedarikciID']);
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

    public function test_ana_gercek_tedarikci_kodu_birebir_kopyalanir(): void
    {
        $payload = $this->mapper->anaToBayiCreatePayload([
            'UrunAdi' => 'X',
            'TedarikciKodu' => 'SUP26|1880|HEB-134',
            'Varyasyonlar' => [
                ['ID' => 1880, 'StokKodu' => 'HEB-134', 'Barkod' => 'B1', 'TedarikciKodu' => 'SUP26|1880|HEB-134'],
                ['ID' => 1881, 'StokKodu' => 'HEB-134', 'Barkod' => 'B2', 'TedarikciKodu' => 'SUP26|1881|HEB-134-XL'],
            ],
        ]);

        // Kart seviyesi: ana'nın gerçek kodu birebir korunur (sentetik üretilmez)
        $this->assertSame('SUP26|1880|HEB-134', $payload['TedarikciKodu']);
        // Varyasyon seviyesi: her varyasyon KENDİ gerçek kodunu taşır
        $this->assertSame('SUP26|1880|HEB-134', $payload['Varyasyonlar'][0]['TedarikciKodu']);
        $this->assertSame('SUP26|1881|HEB-134-XL', $payload['Varyasyonlar'][1]['TedarikciKodu']);
    }

    public function test_tedarikci_kodu_yoksa_sentetik_yedek_uretilir(): void
    {
        $payload = $this->mapper->anaToBayiCreatePayload([
            'UrunAdi' => 'X',
            // TedarikciKodu YOK → birincil varyasyondan sentetik üretilmeli
            'Varyasyonlar' => [
                ['ID' => 42, 'StokKodu' => 'KK-9', 'Barkod' => 'B'],
            ],
        ]);

        $this->assertSame('SUP2026|KK-9|42', $payload['TedarikciKodu']);
        $this->assertSame('SUP2026|KK-9|42', $payload['Varyasyonlar'][0]['TedarikciKodu']);
    }
}
