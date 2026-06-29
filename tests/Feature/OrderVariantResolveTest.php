<?php

namespace Tests\Feature;

use App\Jobs\Concerns\ResolvesAnaVariant;
use App\Models\ProductMapping;
use App\Services\Ticimax\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Sipariş satırı → ana Varyasyon.ID eşleşmesi YALNIZCA TedarikciKodu ile yapılır.
 * Aynı stok_kodu/barkod farklı üründe tekrar edebildiği için stok'a düşmek
 * yanlış ürünü seçebilir — bu testler ted-önceliğini ve stok fallback'inin
 * yalnızca ted BOŞken devreye girdiğini kilitler.
 */
class OrderVariantResolveTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): object
    {
        return new class
        {
            use ResolvesAnaVariant;

            public function go(array $line, ProductService $svc, array &$cache): ?int
            {
                return $this->resolveAnaVariantId($line, $svc, $cache);
            }
        };
    }

    public function test_tedarikci_kodu_lokal_eslesmesi_soap_cagirmaz(): void
    {
        ProductMapping::create([
            'tedarikci_kodu' => 'GIZ|4542|4532',
            'stok_kodu' => '210529',
            'barcode' => 'BC-27713',
            'ana_variant_id' => '27713',
            'status' => 'synced',
        ]);

        // Lokal eşleşme bulunmalı → SOAP'a HİÇ gidilmemeli
        $svc = Mockery::mock(ProductService::class);
        $svc->shouldNotReceive('getProductByTedarikciKodu');
        $svc->shouldNotReceive('getProductByStokKodu');

        $cache = [];
        $id = $this->resolver()->go(
            ['TedarikciKodu' => 'GIZ|4542|4532', 'StokKodu' => '210529'],
            $svc,
            $cache
        );

        $this->assertSame(27713, $id);
    }

    public function test_ted_varken_stok_fallback_kullanilmaz(): void
    {
        // Aynı stok_kodu'na sahip BAŞKA bir ürün mapping'i var (tuzak).
        ProductMapping::create([
            'tedarikci_kodu' => 'GIZ|9999|8888',
            'stok_kodu' => '210529',  // aynı stok — ama farklı ted
            'barcode' => 'BC-11111',
            'ana_variant_id' => '11111',
            'status' => 'synced',
        ]);

        // Gelen satırın ted'i farklı; lokalde ted yok → SOAP ted ile aranmalı,
        // stok ile ASLA aranmamalı (yanlış ürün 11111'i döndürmemeli).
        $svc = Mockery::mock(ProductService::class);
        $svc->shouldReceive('getProductByTedarikciKodu')->once()->andReturn(null);
        $svc->shouldNotReceive('getProductByStokKodu');

        $cache = [];
        $id = $this->resolver()->go(
            ['TedarikciKodu' => 'GIZ|4542|4532', 'StokKodu' => '210529'],
            $svc,
            $cache
        );

        $this->assertNull($id); // yanlış ürüne düşmedi
    }

    public function test_ted_bos_ise_stok_fallback_devreye_girer(): void
    {
        ProductMapping::create([
            'stok_kodu' => 'SK-ONLY',
            'barcode' => 'BC-42',
            'ana_variant_id' => '42',
            'status' => 'synced',
        ]);

        $svc = Mockery::mock(ProductService::class);
        $svc->shouldNotReceive('getProductByTedarikciKodu');
        // stok lokal eşleşmesi var → SOAP'a gerek yok

        $cache = [];
        $id = $this->resolver()->go(
            ['TedarikciKodu' => '', 'StokKodu' => 'SK-ONLY'],
            $svc,
            $cache
        );

        $this->assertSame(42, $id);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
