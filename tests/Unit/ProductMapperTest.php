<?php

namespace Tests\Unit;

use App\Services\Ticimax\ProductMapper;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Yeni şema: bayiOrderToAnaCreatePayload artık bir StokKodu → ana Varyasyon.ID resolver
 * callback'i alır ve WebSiparis envelope formatında payload üretir.
 */
class ProductMapperTest extends TestCase
{
    public function test_maps_bayi_order_to_ana_save_siparis_payload(): void
    {
        $mapper = new ProductMapper();

        $bayiOrder = [
            'ID' => 'B-1234',
            'SiparisNo' => 'B-1234',
            'AdiSoyadi' => 'Ahmet Yılmaz',
            'Mail' => 'ahmet@example.com',
            'Telefon' => '5551234567',
            'KargoFirmaID' => '3',
            'KargoTutari' => 15.0,
            'OdenenTutar' => 265.0,
            'Odeme' => ['OdemeTipi' => '10', 'OdemeDurumu' => '1'],
            'TeslimatAdresi' => ['Adres' => 'Test Mah.', 'Il' => 'İstanbul', 'Ilce' => 'Kadıköy'],
            'FaturaAdresi' => ['Adres' => 'Test Mah.', 'Il' => 'İstanbul', 'Ilce' => 'Kadıköy'],
            'Urunler' => [
                ['StokKodu' => 'SK1', 'UrunAdi' => 'Test Ürün', 'Adet' => 2, 'Tutar' => 125.0, 'KdvOrani' => 20, 'KdvTutari' => 41.67],
            ],
        ];

        $resolver = fn (string $sk) => $sk === 'SK1' ? 999 : null;

        $payload = $mapper->bayiOrderToAnaCreatePayload($bayiOrder, $resolver);

        $this->assertSame('KaravanKids', $payload['SiparisKaynagi']);
        $this->assertSame('B-1234', $payload['SiparisNo']);
        $this->assertSame('Ahmet Yılmaz', $payload['UyeAdi']);
        $this->assertSame('5551234567', $payload['UyeCep']);
        $this->assertSame('ahmet@example.com', $payload['UyeMail']);
        $this->assertSame(3, $payload['KargoFirmaId']);
        $this->assertSame(15.0, $payload['KargoTutari']);
        $this->assertSame('10', $payload['Odeme']['OdemeTipi']);
        $this->assertSame(265.0, $payload['Odeme']['Tutar']);

        $satir = $payload['Urunler']['WebSiparisSaveUrun'][0];
        $this->assertSame(999, $satir['UrunID']);
        $this->assertSame(2, $satir['Adet']);
        $this->assertSame(125.0, $satir['Tutar']);
        $this->assertSame(20.0, $satir['KdvOrani']);
        $this->assertSame(0, $satir['Maliyet']);
        $this->assertSame(false, $satir['MagazaStokKontrolEt']);
    }

    public function test_throws_when_stok_kodu_not_resolvable_in_ana(): void
    {
        $mapper = new ProductMapper();
        $bayiOrder = [
            'SiparisNo' => 'B-9999',
            'Urunler' => [
                ['StokKodu' => 'YOK', 'Adet' => 1, 'Tutar' => 100.0],
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/YOK/');

        $mapper->bayiOrderToAnaCreatePayload($bayiOrder, fn () => null);
    }

    public function test_throws_when_stok_kodu_missing_on_line(): void
    {
        $mapper = new ProductMapper();
        $bayiOrder = [
            'Urunler' => [
                ['Adet' => 1, 'Tutar' => 100.0],
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/StokKodu yok/iu');

        $mapper->bayiOrderToAnaCreatePayload($bayiOrder, fn () => 1);
    }

    public function test_teslimat_address_passthrough(): void
    {
        $mapper = new ProductMapper();
        $bayiOrder = [
            'TeslimatAdresi' => ['Adres' => 'Test', 'Il' => 'Ankara'],
            'Urunler' => [['StokKodu' => 'X', 'Adet' => 1, 'Tutar' => 1.0]],
        ];

        $payload = $mapper->bayiOrderToAnaCreatePayload($bayiOrder, fn () => 1);

        $this->assertIsArray($payload['FaturaAdres']);
        $this->assertSame('Türkiye', $payload['FaturaAdres']['Ulke']);
        $this->assertSame('Ankara', $payload['TeslimatAdres']['Il']);
    }

    public function test_coerces_numeric_strings_to_correct_types(): void
    {
        $mapper = new ProductMapper();
        $bayiOrder = [
            'Urunler' => [
                ['StokKodu' => 'X', 'Adet' => '3', 'Tutar' => '49.90', 'KdvOrani' => '18'],
            ],
        ];

        $payload = $mapper->bayiOrderToAnaCreatePayload($bayiOrder, fn () => 1);
        $satir = $payload['Urunler']['WebSiparisSaveUrun'][0];

        $this->assertSame(3, $satir['Adet']);
        $this->assertSame(49.9, $satir['Tutar']);
        $this->assertSame(18.0, $satir['KdvOrani']);
    }

    public function test_builds_tedarikci_kodu_with_correct_format(): void
    {
        $mapper = new ProductMapper();
        $this->assertSame('SUP|123|MG26A', $mapper->buildTedarikciKodu(123, 'MG26A'));
        $this->assertSame('SUP|99|', $mapper->buildTedarikciKodu(99, ''));
    }

    public function test_ana_to_bayi_payload_embeds_tedarikci_kodu(): void
    {
        $mapper = new ProductMapper();
        $ana = [
            'ID' => 555,
            'UrunAdi' => 'Test',
            'Aktif' => true,
            'StokKodu' => 'ABC',
            'Varyasyonlar' => [
                ['ID' => 1, 'Barkod' => 'B1', 'StokKodu' => 'ABC',
                 'Ozellikler' => ['VaryasyonOzellik' => [['Tanim' => 'Renk', 'Deger' => 'Kırmızı']]]],
                ['ID' => 2, 'Barkod' => 'B2', 'StokKodu' => 'ABC',
                 'Ozellikler' => ['VaryasyonOzellik' => [['Tanim' => 'Renk', 'Deger' => 'Mavi'], ['Tanim' => 'Beden', 'Deger' => 'L']]]],
            ],
        ];

        $payload = $mapper->anaToBayiCreatePayload($ana);

        $this->assertSame('SUP|555|ABC', $payload['TedarikciKodu']);
        $this->assertSame('SUP|555|ABC|Kırmızı', $payload['Varyasyonlar'][0]['TedarikciKodu']);
        $this->assertSame('SUP|555|ABC|Mavi|L', $payload['Varyasyonlar'][1]['TedarikciKodu']);
    }
}
