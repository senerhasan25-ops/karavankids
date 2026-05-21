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

    public function test_get_new_products_listeyi_normalize_eder(): void
    {
        $client = Mockery::mock(TicimaxClient::class);
        $client->shouldReceive('getAuth')->andReturn(['UyeKodu' => 'u', 'UyeSifre' => 'p']);
        $client->shouldReceive('call')->once()->with('product', 'SelectUrunler', Mockery::on(function ($params) {
            return isset($params['f']['Sayfa']) && $params['f']['Sayfa'] === 1;
        }))->andReturn((object) [
            'SelectUrunlerResult' => (object) [
                'Urun' => [
                    (object) ['Barkod' => 'B1', 'UrunAdi' => 'X', 'UrunKartiID' => 10],
                    (object) ['Barkod' => 'B2', 'UrunAdi' => 'Y', 'UrunKartiID' => 11],
                ],
            ],
        ]);

        $svc = new ProductService($client);
        $result = $svc->getNewProducts();

        $this->assertCount(2, $result);
        $this->assertSame('B1', $result[0]['Barkod']);
        $this->assertSame(10, $result[0]['UrunKartiID']);
    }

    public function test_get_new_products_bos_donerse_bos_dizi(): void
    {
        $client = Mockery::mock(TicimaxClient::class);
        $client->shouldReceive('getAuth')->andReturn([]);
        $client->shouldReceive('call')->andReturn(null);

        $svc = new ProductService($client);
        $this->assertSame([], $svc->getNewProducts());
    }

    public function test_create_product_payload_dogru_gonderir(): void
    {
        $client = Mockery::mock(TicimaxClient::class);
        $client->shouldReceive('getAuth')->andReturn(['UyeKodu' => 'u']);
        $client->shouldReceive('call')->once()
            ->with('product', 'SaveUrun', Mockery::on(fn ($p) => $p['urun']['Barkod'] === 'NEW1'))
            ->andReturn((object) ['UrunKartiID' => 99]);

        $svc = new ProductService($client);
        $result = $svc->createProduct(['Barkod' => 'NEW1', 'UrunAdi' => 'Yeni']);

        $this->assertSame(99, $result['UrunKartiID']);
    }

    public function test_update_stock_price_dogru_method_cagirir(): void
    {
        $client = Mockery::mock(TicimaxClient::class);
        $client->shouldReceive('getAuth')->andReturn([]);
        $client->shouldReceive('call')->once()
            ->with('product', 'SetUrunStokFiyat', Mockery::on(function ($p) {
                return $p['urunId'] === '123' && $p['StokAdedi'] === 7 && $p['SatisFiyati'] === 99.5;
            }))
            ->andReturn((object) ['ok' => true]);

        $svc = new ProductService($client);
        $svc->updateStockAndPrice('123', 7, 99.5);

        $this->addToAssertionCount(1);
    }
}
