<?php

namespace Tests\Unit;

use App\Services\Ticimax\ProductMapper;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ProductMapperTest extends TestCase
{
    public function test_maps_bayi_order_to_ana_payload(): void
    {
        $mapper = new ProductMapper();

        $bayiOrder = [
            'SiparisKodu' => 'B-1234',
            'UyeKodu' => 'BAYI007',
            'MusteriAdSoyad' => 'Ahmet Yılmaz',
            'Email' => 'ahmet@example.com',
            'Telefon' => '5551234567',
            'OdemeYontemi' => 'KrediKarti',
            'KargoFirmasi' => 'Aras',
            'AraToplam' => 250.0,
            'KargoTutari' => 15.0,
            'GenelToplam' => 265.0,
            'TeslimatAdresi' => ['AdresSatir1' => 'Test Mah.', 'Il' => 'İstanbul'],
            'FaturaAdresi' => ['AdresSatir1' => 'Test Mah.', 'Il' => 'İstanbul'],
            'Urunler' => [
                ['Barkod' => 'B001', 'StokKodu' => 'SK1', 'UrunAdi' => 'Test Ürün', 'Adet' => 2, 'BirimFiyat' => 125.0, 'KdvOrani' => 20],
            ],
        ];

        $map = ['B001' => 999]; // barcode → ana_product_id

        $payload = $mapper->bayiOrderToAnaCreatePayload($bayiOrder, $map);

        $this->assertSame('BayiPaneli', $payload['SiparisKaynagi']);
        $this->assertStringContainsString('B-1234', $payload['Aciklama']);
        $this->assertStringContainsString('BAYI007', $payload['AdminNotu']);
        $this->assertSame('Ahmet Yılmaz', $payload['Uye']['AdSoyad']);
        $this->assertSame('5551234567', $payload['Uye']['TelefonCep']);
        $this->assertSame('KrediKarti', $payload['OdemeYontemi']);
        $this->assertSame(265.0, $payload['GenelToplam']);

        $this->assertCount(1, $payload['Urunler']);
        $line = $payload['Urunler'][0];
        $this->assertSame(999, $line['UrunKartiID']);
        $this->assertSame('B001', $line['Barkod']);
        $this->assertSame(2, $line['Adet']);
        $this->assertSame(125.0, $line['BirimFiyat']);
    }

    public function test_throws_when_barcode_missing_from_mapping(): void
    {
        $mapper = new ProductMapper();

        $bayiOrder = [
            'SiparisKodu' => 'B-9999',
            'Urunler' => [
                ['Barkod' => 'UNKNOWN', 'Adet' => 1, 'BirimFiyat' => 100.0],
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/UNKNOWN/');

        $mapper->bayiOrderToAnaCreatePayload($bayiOrder, []);
    }

    public function test_throws_when_barcode_missing_from_line(): void
    {
        $mapper = new ProductMapper();

        $bayiOrder = [
            'Urunler' => [
                ['Adet' => 1, 'BirimFiyat' => 100.0], // no Barkod field
            ],
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/yok|null|eşleşme/iu');

        $mapper->bayiOrderToAnaCreatePayload($bayiOrder, []);
    }

    public function test_defaults_fatura_to_teslimat_when_missing(): void
    {
        $mapper = new ProductMapper();
        $bayiOrder = [
            'TeslimatAdresi' => ['Il' => 'Ankara'],
            'Urunler' => [['Barkod' => 'X', 'Adet' => 1, 'BirimFiyat' => 1.0]],
        ];

        $payload = $mapper->bayiOrderToAnaCreatePayload($bayiOrder, ['X' => 1]);

        $this->assertSame(['Il' => 'Ankara'], $payload['FaturaAdresi']);
    }

    public function test_coerces_numeric_strings_to_correct_types(): void
    {
        $mapper = new ProductMapper();
        $bayiOrder = [
            'Urunler' => [
                ['Barkod' => 'X', 'Adet' => '3', 'BirimFiyat' => '49.90', 'KdvOrani' => '18'],
            ],
        ];

        $payload = $mapper->bayiOrderToAnaCreatePayload($bayiOrder, ['X' => 1]);
        $line = $payload['Urunler'][0];

        $this->assertSame(3, $line['Adet']);
        $this->assertSame(49.9, $line['BirimFiyat']);
        $this->assertSame(18, $line['KdvOrani']);
    }
}
