<?php

namespace Tests\Unit\Ticimax;

use App\Services\Ticimax\ProductService;
use App\Services\Ticimax\TicimaxClient;
use Mockery;
use Tests\TestCase;

/**
 * Ticimax pagination bug tespiti (#2) + dilimleyerek kurtarma testleri.
 */
class PaginationRecoveryTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_pagination_bug_mesaji_dogru_taninir(): void
    {
        $svc = new ProductService(Mockery::mock(TicimaxClient::class));

        $this->assertTrue($svc->isTicimaxPaginationBug(
            new \Exception('Ticimax call failed after 3 attempts: Value cannot be null. Parameter name: source')
        ));
        // Alakasız hata → false
        $this->assertFalse($svc->isTicimaxPaginationBug(new \Exception('Connection timed out')));
        // Sadece bir kısmı eşleşiyorsa → false (ikisi birden gerekir)
        $this->assertFalse($svc->isTicimaxPaginationBug(new \Exception('Value cannot be null somewhere')));
    }

    public function test_normal_sayfa_bug_yoksa_dogrudan_doner(): void
    {
        $client = Mockery::mock(TicimaxClient::class);
        $client->shouldReceive('getUyeKodu')->andReturn('U');
        $client->shouldReceive('call')->once()->andReturn((object) [
            'SelectUrunResult' => (object) [
                'UrunKarti' => [(object) ['ID' => 1], (object) ['ID' => 2]],
            ],
        ]);

        $svc = new ProductService($client);
        $r = $svc->fetchProductPageRecovering('created', null, 1, 100);

        $this->assertFalse($r['bug']);
        $this->assertSame(2, $r['recovered']);
        $this->assertSame(0, $r['lost']);
        $this->assertCount(2, $r['products']);
    }

    public function test_bug_sayfasi_dilimlenerek_kurtarilir(): void
    {
        $client = Mockery::mock(TicimaxClient::class);
        $client->shouldReceive('getUyeKodu')->andReturn('U');

        // Tam sayfa (KayitSayisi=100) → bug fırlat.
        // Alt dilimler (KayitSayisi=10): offset 50 hariç her dilim 2 ürün döner,
        // offset 50 yine bug fırlatır (kayıp dilim).
        $client->shouldReceive('call')->andReturnUsing(function ($svc, $method, $params) {
            $size = $params['s']['KayitSayisi'];
            $idx = $params['s']['BaslangicIndex'];

            if ($size === 100) {
                throw new \Exception('Value cannot be null. Parameter name: source');
            }
            if ($idx === 50) {
                throw new \Exception('Value cannot be null. Parameter name: source');
            }

            // Her dilim için benzersiz ID'ler üret (idx tabanlı)
            return (object) [
                'SelectUrunResult' => (object) [
                    'UrunKarti' => [
                        (object) ['ID' => $idx + 1],
                        (object) ['ID' => $idx + 2],
                    ],
                ],
            ];
        });

        $svc = new ProductService($client);
        // page=1, perPage=100, subStep=10 → offsetler 0,10,...,90 (10 dilim)
        $r = $svc->fetchProductPageRecovering('created', null, 1, 100, 'ASC', 10);

        $this->assertTrue($r['bug']);
        // 9 başarılı dilim × 2 ürün = 18 kurtarıldı, 1 dilim (offset 50) × 10 = 10 kayıp
        $this->assertSame(18, $r['recovered']);
        $this->assertSame(10, $r['lost']);
        $this->assertCount(18, $r['products']);
    }

    public function test_kurtarma_sirasinda_id_cakismasi_dedupe_edilir(): void
    {
        $client = Mockery::mock(TicimaxClient::class);
        $client->shouldReceive('getUyeKodu')->andReturn('U');

        $client->shouldReceive('call')->andReturnUsing(function ($svc, $method, $params) {
            if ($params['s']['KayitSayisi'] === 100) {
                throw new \Exception('Value cannot be null. Parameter name: source');
            }

            // Her dilim AYNI ID'yi döndürsün → dedupe sonrası tek kayıt kalmalı
            return (object) [
                'SelectUrunResult' => (object) ['UrunKarti' => [(object) ['ID' => 777]]],
            ];
        });

        $svc = new ProductService($client);
        $r = $svc->fetchProductPageRecovering('created', null, 1, 100, 'ASC', 10);

        $this->assertTrue($r['bug']);
        $this->assertSame(1, $r['recovered']); // 10 dilim aynı ID → dedupe → 1
    }

    public function test_gercek_hata_recovery_tetiklemeden_firlatilir(): void
    {
        $client = Mockery::mock(TicimaxClient::class);
        $client->shouldReceive('getUyeKodu')->andReturn('U');
        $client->shouldReceive('call')->once()->andThrow(new \Exception('Auth failed: invalid token'));

        $svc = new ProductService($client);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Auth failed');
        $svc->fetchProductPageRecovering('created', null, 1, 100);
    }

    public function test_bilinmeyen_filtre_tipi_reddedilir(): void
    {
        $svc = new ProductService(Mockery::mock(TicimaxClient::class));

        $this->expectException(\InvalidArgumentException::class);
        $svc->fetchProductPageRecovering('gecersiz_tip', null, 1, 100);
    }
}
