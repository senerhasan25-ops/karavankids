<?php

namespace App\Services\Ticimax;

class ProductMapper
{
    public function anaToBayiCreatePayload(array $ana): array
    {
        return [
            'UrunKartiID' => 0,
            'StokKodu' => $ana['StokKodu'] ?? ($ana['Barkod'] ?? ''),
            'Barkod' => $ana['Barkod'] ?? '',
            'UrunAdi' => $ana['UrunAdi'] ?? '',
            'KisaAciklama' => $ana['KisaAciklama'] ?? '',
            'Aciklama' => $ana['Aciklama'] ?? '',
            'KategoriID' => $ana['KategoriID'] ?? 0,
            'KategoriYolu' => $ana['KategoriYolu'] ?? '',
            'Marka' => $ana['Marka'] ?? '',
            'Aktif' => $ana['Aktif'] ?? true,

            'SatisFiyati' => (float) ($ana['SatisFiyati'] ?? 0),
            'IndirimliFiyat' => (float) ($ana['IndirimliFiyat'] ?? 0),
            'AlisFiyati' => (float) ($ana['AlisFiyati'] ?? 0),
            'KDVDahil' => (bool) ($ana['KDVDahil'] ?? true),
            'KdvOrani' => (int) ($ana['KdvOrani'] ?? 20),
            'ParaBirimi' => $ana['ParaBirimi'] ?? 'TRY',

            'StokAdedi' => (int) ($ana['StokAdedi'] ?? 0),
            'StokTakibi' => (bool) ($ana['StokTakibi'] ?? true),

            'Desi' => (float) ($ana['Desi'] ?? 0),
            'Agirlik' => (float) ($ana['Agirlik'] ?? 0),

            'SeoBaslik' => $ana['SeoBaslik'] ?? ($ana['UrunAdi'] ?? ''),
            'SeoAciklama' => $ana['SeoAciklama'] ?? ($ana['KisaAciklama'] ?? ''),
            'SeoAnahtarKelimeler' => $ana['SeoAnahtarKelimeler'] ?? '',
            'SeoUrl' => $ana['SeoUrl'] ?? '',

            'Resimler' => $this->mapImages($ana['Resimler'] ?? []),
            'Varyasyonlar' => $this->mapVariations($ana['Varyasyonlar'] ?? []),
        ];
    }

    public function bayiOrderToAnaCreatePayload(array $bayiOrder, array $barcodeToAnaIdMap): array
    {
        $satirlar = [];
        foreach (($bayiOrder['Urunler'] ?? []) as $line) {
            $barkod = $line['Barkod'] ?? null;
            if (! $barkod || ! isset($barcodeToAnaIdMap[$barkod])) {
                throw new \RuntimeException("Bayi siparişindeki ürün ana tarafta eşleşmedi (barkod: " . ($barkod ?? 'yok') . ")");
            }
            $satirlar[] = [
                'UrunKartiID' => $barcodeToAnaIdMap[$barkod],
                'Barkod' => $barkod,
                'StokKodu' => $line['StokKodu'] ?? '',
                'UrunAdi' => $line['UrunAdi'] ?? '',
                'Adet' => (int) ($line['Adet'] ?? 1),
                'BirimFiyat' => (float) ($line['BirimFiyat'] ?? 0),
                'KdvOrani' => (int) ($line['KdvOrani'] ?? 20),
            ];
        }

        return [
            'SiparisKaynagi' => 'BayiPaneli',
            'Aciklama' => 'Bayi siparişi: ' . ($bayiOrder['SiparisKodu'] ?? ''),
            'AdminNotu' => 'Bayi: ' . ($bayiOrder['UyeKodu'] ?? ''),
            'Uye' => [
                'AdSoyad' => $bayiOrder['MusteriAdSoyad'] ?? ($bayiOrder['UyeAdSoyad'] ?? ''),
                'Email' => $bayiOrder['Email'] ?? '',
                'TelefonCep' => $bayiOrder['Telefon'] ?? '',
            ],
            'TeslimatAdresi' => $bayiOrder['TeslimatAdresi'] ?? [],
            'FaturaAdresi' => $bayiOrder['FaturaAdresi'] ?? ($bayiOrder['TeslimatAdresi'] ?? []),
            'OdemeYontemi' => $bayiOrder['OdemeYontemi'] ?? 'Havale',
            'KargoFirmasi' => $bayiOrder['KargoFirmasi'] ?? '',
            'Urunler' => $satirlar,
            'AraToplam' => (float) ($bayiOrder['AraToplam'] ?? 0),
            'KargoTutari' => (float) ($bayiOrder['KargoTutari'] ?? 0),
            'GenelToplam' => (float) ($bayiOrder['GenelToplam'] ?? 0),
        ];
    }

    protected function mapImages(array $images): array
    {
        return array_values(array_filter(array_map(
            fn ($img) => is_string($img) ? $img : ($img['ResimUrl'] ?? $img['Url'] ?? null),
            $images
        )));
    }

    protected function mapVariations(array $variations): array
    {
        return array_map(fn ($v) => [
            'Barkod' => $v['Barkod'] ?? '',
            'StokKodu' => $v['StokKodu'] ?? '',
            'StokAdedi' => (int) ($v['StokAdedi'] ?? 0),
            'SatisFiyati' => (float) ($v['SatisFiyati'] ?? 0),
            'IndirimliFiyat' => (float) ($v['IndirimliFiyat'] ?? 0),
            'Ozellikler' => $v['Ozellikler'] ?? [],
            'Resimler' => $this->mapImages($v['Resimler'] ?? []),
            'Aktif' => (bool) ($v['Aktif'] ?? true),
        ], $variations);
    }
}
