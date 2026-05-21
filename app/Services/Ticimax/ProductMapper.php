<?php

namespace App\Services\Ticimax;

/**
 * Ticimax UrunKarti/Varyasyon yapısı ile çalışır.
 * Ana ve bayi aynı şemayı kullandığı için "mapper" temelde:
 *  - ID alanlarını sıfırlar (yeni ürün için ana'nın ID'sini bayi'ye geçirme)
 *  - Sadece SaveUrun'un beklediği alt kümeyi geçirir
 *  - Varyasyon listesini düzenler
 *  - Bayi siparişini ana'ya yazmak için ters yön mapper
 */
class ProductMapper
{
    /** @var callable|null  Marka adı → bayi marka ID'si çözümleyici (opsiyonel) */
    protected $brandResolver = null;

    /** @var callable|null  Tedarikçi adı → bayi tedarikçi ID'si çözümleyici */
    protected $supplierResolver = null;

    public function setBrandResolver(?callable $resolver): void
    {
        $this->brandResolver = $resolver;
    }

    public function setSupplierResolver(?callable $resolver): void
    {
        $this->supplierResolver = $resolver;
    }

    /**
     * Ana mağazadan gelen UrunKarti objesini bayi'ye SaveUrun payload'una çevirir.
     */
    public function anaToBayiCreatePayload(array $ana): array
    {
        $kart = [
            'ID' => 0, // yeni kayıt — ana'nın ID'sini geçirme
            'Aktif' => (bool) ($ana['Aktif'] ?? true),
            'UrunAdi' => (string) ($ana['UrunAdi'] ?? ''),
            'Aciklama' => (string) ($ana['Aciklama'] ?? ''),
            'OnYazi' => (string) ($ana['OnYazi'] ?? ''),
            'AramaAnahtarKelime' => (string) ($ana['AramaAnahtarKelime'] ?? ''),

            // Kategori ID'leri de mağazalar arasında farklı olabilir; isim ile eşletmek
            // için ID 0 gönderiyoruz. Eğer bayi'de aynı kategori varsa otomatik eşleşir.
            'AnaKategori' => (string) ($ana['AnaKategori'] ?? ''),
            'AnaKategoriID' => 0,
            'Kategoriler' => [], // ana mağazadaki ID'ler bayi'de geçerli değil — boş bırakıyoruz

            // MarkaID ana ve bayi'de farklı; eğer brand resolver verilmişse adına göre
            // bayi ID'sini bul/oluştur, yoksa 0 gönder (ürün markasız oluşur).
            'Marka' => (string) ($ana['Marka'] ?? ''),
            'MarkaID' => $this->resolveBrandId((string) ($ana['Marka'] ?? '')),

            'SeoSayfaBaslik' => (string) ($ana['SeoSayfaBaslik'] ?? $ana['UrunAdi'] ?? ''),
            'SeoSayfaAciklama' => (string) ($ana['SeoSayfaAciklama'] ?? ($ana['OnYazi'] ?? '')),
            'SeoAnahtarKelime' => (string) ($ana['SeoAnahtarKelime'] ?? ''),
            'UrunSayfaAdresi' => (string) ($ana['UrunSayfaAdresi'] ?? ''),

            'ListedeGoster' => (bool) ($ana['ListedeGoster'] ?? true),
            'Vitrin' => (bool) ($ana['Vitrin'] ?? false),
            'YeniUrun' => (bool) ($ana['YeniUrun'] ?? false),

            // Tedarikçi ana ID → bayi ID (resolver ana adına çevirip bayi'de bulur/oluşturur)
            'TedarikciID' => $this->resolveSupplierId((int) ($ana['TedarikciID'] ?? 0)),
            'TedarikciKodu' => '',
            'TedarikciKodu2' => '',

            'Resimler' => $this->mapImages($ana['Resimler'] ?? []),

            'Varyasyonlar' => $this->mapVariations($ana['Varyasyonlar'] ?? []),
        ];

        // Eğer ana'da hiç varyasyon yoksa, üst seviyedeki barkod/stok/fiyat alanlarından
        // 1 tane varsayılan varyasyon oluştur (Ticimax böyle ürünleri de Varyasyon altında saklar).
        if (empty($kart['Varyasyonlar']) && ($ana['Barkod'] ?? null || $ana['StokKodu'] ?? null)) {
            $kart['Varyasyonlar'] = [$this->fallbackVariantFromTopLevel($ana)];
        }

        return $kart;
    }

    /**
     * Bayi siparişini ana mağazada SaveSiparis için payload'a çevirir.
     */
    public function bayiOrderToAnaCreatePayload(array $bayiOrder, array $barcodeToAnaIdMap): array
    {
        $satirlar = [];
        foreach (($bayiOrder['Urunler'] ?? []) as $line) {
            $barkod = $line['Barkod'] ?? null;
            if (! $barkod || ! isset($barcodeToAnaIdMap[$barkod])) {
                throw new \RuntimeException("Bayi siparişindeki ürün ana tarafta eşleşmedi (barkod: " . ($barkod ?? 'yok') . ")");
            }
            $satirlar[] = [
                'UrunKartiID' => (int) $barcodeToAnaIdMap[$barkod],
                'Barkod' => $barkod,
                'StokKodu' => (string) ($line['StokKodu'] ?? ''),
                'UrunAdi' => (string) ($line['UrunAdi'] ?? ''),
                'Adet' => (int) ($line['Adet'] ?? 1),
                'BirimFiyat' => (float) ($line['BirimFiyat'] ?? ($line['SatisFiyati'] ?? 0)),
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

    /**
     * Ana mağazada Aktif=false bir ürün için: bayi'de sadece UrunKarti.Aktif=false yapacak update payload'u.
     */
    public function deactivatePayload(): array
    {
        return ['Aktif' => false];
    }

    /**
     * Ana üründen gelen Resimler — ArrayOfstring (URL listesi) bekleniyor.
     * Ana mağazanın CDN URL'leri olduğu gibi gönderilir, bayi tekrar indirir.
     */
    protected function mapImages(mixed $images): array
    {
        if (! is_array($images)) {
            return [];
        }
        // ArrayOfstring SOAP wrapper: ['string' => [...]] olabilir
        if (isset($images['string'])) {
            $images = is_array($images['string']) && array_is_list($images['string']) ? $images['string'] : [$images['string']];
        }
        return array_values(array_filter(array_map(function ($img) {
            if (is_string($img)) {
                return $img;
            }
            if (is_array($img)) {
                return $img['ResimUrl'] ?? $img['Url'] ?? $img['ResimAdi'] ?? null;
            }
            return null;
        }, $images)));
    }

    /**
     * Varyasyon listesini Ticimax Varyasyon şemasına göre üretir.
     */
    protected function mapVariations(mixed $variations): array
    {
        if (! is_array($variations)) {
            return [];
        }
        // ArrayOfVaryasyon wrapper
        if (isset($variations['Varyasyon'])) {
            $variations = is_array($variations['Varyasyon']) && array_is_list($variations['Varyasyon']) ? $variations['Varyasyon'] : [$variations['Varyasyon']];
        }

        return array_map(fn ($v) => [
            'ID' => 0,
            'UrunKartiID' => 0,
            'Aktif' => (bool) ($v['Aktif'] ?? true),
            'Barkod' => (string) ($v['Barkod'] ?? ''),
            'StokKodu' => (string) ($v['StokKodu'] ?? ''),
            'StokAdedi' => (float) ($v['StokAdedi'] ?? 0),
            'SatisFiyati' => (float) ($v['SatisFiyati'] ?? 0),
            'IndirimliFiyati' => (float) ($v['IndirimliFiyati'] ?? 0),
            'AlisFiyati' => (float) ($v['AlisFiyati'] ?? 0),
            'PiyasaFiyati' => (float) ($v['PiyasaFiyati'] ?? 0),
            'KdvOrani' => (float) ($v['KdvOrani'] ?? 20),
            'KdvDahil' => (bool) ($v['KdvDahil'] ?? true),
            'ParaBirimi' => (string) ($v['ParaBirimi'] ?? 'TL'),
            'ParaBirimiID' => (int) ($v['ParaBirimiID'] ?? 1), // 1 = TL (default)
            'Desi' => (float) ($v['Desi'] ?? 0),
            'UrunAgirligi' => (float) ($v['UrunAgirligi'] ?? 0),
            'Ozellikler' => $this->mapVariantProps($v['Ozellikler'] ?? []),
            'Resimler' => $this->mapImages($v['Resimler'] ?? []),
        ], $variations);
    }

    /**
     * Varyasyon özellikleri (renk/beden vb).
     */
    protected function mapVariantProps(mixed $props): array
    {
        if (! is_array($props)) {
            return [];
        }
        if (isset($props['VaryasyonOzellik'])) {
            $props = is_array($props['VaryasyonOzellik']) && array_is_list($props['VaryasyonOzellik']) ? $props['VaryasyonOzellik'] : [$props['VaryasyonOzellik']];
        }
        return array_map(fn ($p) => [
            'Tanim' => (string) ($p['Tanim'] ?? ''),
            'Deger' => (string) ($p['Deger'] ?? ''),
            'RenkKodu' => (string) ($p['RenkKodu'] ?? ''),
        ], $props);
    }

    /**
     * Varyasyonsuz ürünlerde üst seviyeden tek bir Varyasyon objesi türetir.
     */
    protected function fallbackVariantFromTopLevel(array $ana): array
    {
        return [
            'ID' => 0,
            'UrunKartiID' => 0,
            'Aktif' => true,
            'Barkod' => (string) ($ana['Barkod'] ?? ''),
            'StokKodu' => (string) ($ana['StokKodu'] ?? $ana['Barkod'] ?? ''),
            'StokAdedi' => (float) ($ana['StokAdedi'] ?? $ana['ToplamStokAdedi'] ?? 0),
            'SatisFiyati' => (float) ($ana['SatisFiyati'] ?? 0),
            'IndirimliFiyati' => (float) ($ana['IndirimliFiyati'] ?? 0),
            'AlisFiyati' => (float) ($ana['AlisFiyati'] ?? 0),
            'KdvOrani' => (float) ($ana['KdvOrani'] ?? 20),
            'KdvDahil' => (bool) ($ana['KdvDahil'] ?? true),
            'ParaBirimi' => (string) ($ana['ParaBirimi'] ?? 'TL'),
            'ParaBirimiID' => (int) ($ana['ParaBirimiID'] ?? 1), // 1 = TL
            'Desi' => (float) ($ana['Desi'] ?? 0),
            'Ozellikler' => [],
            'Resimler' => [],
        ];
    }

    protected function resolveBrandId(string $name): int
    {
        if ($name === '' || $this->brandResolver === null) {
            return 0;
        }
        try {
            return (int) call_user_func($this->brandResolver, $name);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    protected function resolveSupplierId(int $anaSupplierId): int
    {
        if ($anaSupplierId === 0 || $this->supplierResolver === null) {
            return 0;
        }
        try {
            return (int) call_user_func($this->supplierResolver, $anaSupplierId);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    protected function normalizeIntList(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }
        if (isset($list['int'])) {
            $list = is_array($list['int']) && array_is_list($list['int']) ? $list['int'] : [$list['int']];
        }
        return array_values(array_filter(array_map(fn ($v) => is_numeric($v) ? (int) $v : null, $list), fn ($v) => $v !== null));
    }
}
