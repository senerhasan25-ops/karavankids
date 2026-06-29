<?php

namespace Tests\Unit\Ticimax;

use App\Services\Ticimax\ProductService;
use App\Services\Ticimax\TicimaxClient;
use Mockery;
use Tests\TestCase;

class ProductServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_get_new_products_select_urun_dogru_parametrelerle_cagrir(): void
    {
        $client = Mockery::mock(TicimaxClient::class);
        $client->shouldReceive('getUyeKodu')->andReturn('U123');
        $client->shouldReceive('call')->once()
            ->with('product', 'SelectUrun', Mockery::on(function ($params) {
                return $params['UyeKodu'] === 'U123'
                    && isset($params['f']['Aktif'])
                    && $params['s']['BaslangicIndex'] === 0
                    && $params['s']['KayitSayisi'] === 50;
            }))
            ->andReturn((object) [
                'SelectUrunResult' => (object) [
                    'UrunKarti' => [
                        (object) ['ID' => 10, 'UrunAdi' => 'X'],
                        (object) ['ID' => 11, 'UrunAdi' => 'Y'],
                    ],
                ],
            ]);

        $svc = new ProductService($client);
        $result = $svc->getNewProducts(null, 1, 50);

        $this->assertCount(2, $result);
        $this->assertSame(10, $result[0]['ID']);
    }

    public function test_get_new_products_bos_donerse_bos_dizi(): void
    {
        $client = Mockery::mock(TicimaxClient::class);
        $client->shouldReceive('getUyeKodu')->andReturn('U');
        $client->shouldReceive('call')->andReturn(null);

        $svc = new ProductService($client);
        $this->assertSame([], $svc->getNewProducts());
    }

    public function test_get_product_by_barcode_select_urun_filtreli_cagirir(): void
    {
        $client = Mockery::mock(TicimaxClient::class);
        $client->shouldReceive('getUyeKodu')->andReturn('U');
        $client->shouldReceive('call')->once()
            ->with('product', 'SelectUrun', Mockery::on(fn ($p) => ($p['f']['Barkod'] ?? null) === 'B123'))
            ->andReturn((object) [
                'SelectUrunResult' => (object) ['UrunKarti' => (object) ['ID' => 5, 'UrunAdi' => 'Bulundu']],
            ]);

        $svc = new ProductService($client);
        $result = $svc->getProductByBarcode('B123');

        $this->assertSame(5, $result['ID']);
        $this->assertSame('Bulundu', $result['UrunAdi']);
    }

    public function test_create_product_save_urun_batch_olarak_cagirir(): void
    {
        $client = Mockery::mock(TicimaxClient::class);
        $client->shouldReceive('getUyeKodu')->andReturn('U');
        $client->shouldReceive('call')->once()
            ->with('product', 'SaveUrun', Mockery::on(function ($p) {
                return isset($p['urunKartlari'])
                    && is_array($p['urunKartlari'])
                    && count($p['urunKartlari']) === 1
                    && $p['urunKartlari'][0]['UrunAdi'] === 'Yeni'
                    && isset($p['ukAyar']['AktifGuncelle'])
                    && isset($p['vAyar']['BarkodGuncelle']);
            }))
            ->andReturn((object) ['SaveUrunResult' => 1, 'urunKartlari' => (object) ['UrunKarti' => (object) ['ID' => 99]]]);

        $svc = new ProductService($client);
        $result = $svc->createProduct(['UrunAdi' => 'Yeni', 'Varyasyonlar' => []]);

        $this->assertSame(99, $result['ID']);
    }

    public function test_update_stock_stok_adedi_guncelle_varyasyon_listesi_cagirir(): void
    {
        $client = Mockery::mock(TicimaxClient::class);
        $client->shouldReceive('getUyeKodu')->andReturn('U');
        $client->shouldReceive('call')->once()
            ->with('product', 'StokAdediGuncelle', Mockery::on(function ($p) {
                return is_array($p['urunler'])
                    && $p['urunler'][0]['ID'] === 9472
                    && $p['urunler'][0]['StokAdedi'] === 7
                    && $p['urunler'][0]['Barkod'] === 'B-123';
            }))
            ->andReturn((object) ['StokAdediGuncelleResult' => 1]);

        $svc = new ProductService($client);
        $svc->updateStock('9472', 7, 'B-123');
        $this->addToAssertionCount(1);
    }

    public function test_update_price_update_urun_fiyat_barkod_ve_ayar_cagirir(): void
    {
        $client = Mockery::mock(TicimaxClient::class);
        $client->shouldReceive('getUyeKodu')->andReturn('U');
        $client->shouldReceive('call')->once()
            ->with('product', 'UpdateUrunFiyat', Mockery::on(function ($p) {
                return $p['request'][0]['Barkod'] === 'B-123'
                    && $p['request'][0]['Fiyatlar']['SatisFiyati'] === 99.5
                    && $p['ayar']['BarkodKodunaGoreGuncelle'] === true;
            }))
            ->andReturn((object) ['UpdateUrunFiyatResult' => (object) ['IsError' => false]]);

        $svc = new ProductService($client);
        $svc->updatePrice('B-123', 99.5);
        $this->addToAssertionCount(1);
    }

    public function test_update_stock_and_price_iki_ayri_call_yapar(): void
    {
        $client = Mockery::mock(TicimaxClient::class);
        $client->shouldReceive('getUyeKodu')->andReturn('U');
        $client->shouldReceive('call')->once()
            ->with('product', 'StokAdediGuncelle', Mockery::any())->andReturn((object) ['ok' => true]);
        $client->shouldReceive('call')->once()
            ->with('product', 'UpdateUrunFiyat', Mockery::any())->andReturn((object) ['ok' => true]);

        $svc = new ProductService($client);
        $svc->updateStockAndPrice('9472', 7, 99.5, 'B-123');
        $this->addToAssertionCount(1);
    }

    public function test_set_active_save_urun_uzerinden_aktif_alani_guceller(): void
    {
        $client = Mockery::mock(TicimaxClient::class);
        $client->shouldReceive('getUyeKodu')->andReturn('U');
        $client->shouldReceive('call')->once()
            ->with('product', 'SaveUrun', Mockery::on(function ($p) {
                return $p['urunKartlari'][0]['Aktif'] === false
                    && $p['urunKartlari'][0]['ID'] === 99;
            }))
            ->andReturn((object) ['SaveUrunResult' => 1]);

        $svc = new ProductService($client);
        $svc->setActive('99', false);
        $this->addToAssertionCount(1);
    }

    public function test_selective_update_resimler_secili_degilse_payloaddan_cikarilir(): void
    {
        $client = Mockery::mock(TicimaxClient::class);
        $client->shouldReceive('getUyeKodu')->andReturn('U');
        $client->shouldReceive('call')->once()
            ->with('product', 'SaveUrun', Mockery::on(function ($p) {
                $kart = $p['urunKartlari'][0];
                $kartResimBos = empty($kart['Resimler']);
                $varResimBos = empty($kart['Varyasyonlar'][0]['Resimler']);

                return $kartResimBos && $varResimBos;
            }))
            ->andReturn((object) ['SaveUrunResult' => 1, 'urunKartlari' => (object) ['UrunKarti' => (object) ['ID' => 5]]]);

        $svc = new ProductService($client);
        $svc->updateProductSelective(
            [
                'UrunAdi' => 'X',
                'Resimler' => ['string' => ['https://a.jpg', 'https://b.jpg']],
                'Varyasyonlar' => [
                    ['StokKodu' => 'SK', 'Resimler' => ['string' => ['https://c.jpg']]],
                ],
            ],
            5,
            [],
            ['urun_adi'] // 'resimler' SEÇİLİ DEĞİL
        );
        $this->addToAssertionCount(1);
    }

    public function test_selective_update_resimler_seciliyse_payloadda_kalir(): void
    {
        $client = Mockery::mock(TicimaxClient::class);
        $client->shouldReceive('getUyeKodu')->andReturn('U');
        $client->shouldReceive('call')->once()
            ->with('product', 'SaveUrun', Mockery::on(function ($p) {
                return ! empty($p['urunKartlari'][0]['Resimler'])
                    && $p['ukAyar']['UrunResimGuncelle'] === true;
            }))
            ->andReturn((object) ['SaveUrunResult' => 1, 'urunKartlari' => (object) ['UrunKarti' => (object) ['ID' => 5]]]);

        $svc = new ProductService($client);
        $svc->updateProductSelective(
            [
                'UrunAdi' => 'X',
                'Resimler' => ['string' => ['https://a.jpg']],
                'Varyasyonlar' => [],
            ],
            5,
            [],
            ['urun_adi', 'resimler'] // 'resimler' SEÇİLİ
        );
        $this->addToAssertionCount(1);
    }
}
