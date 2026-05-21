<?php

namespace App\Services\Ticimax;

use Illuminate\Support\Carbon;

class ProductService
{
    public function __construct(protected TicimaxClient $client)
    {
    }

    public static function for(string $storeKey): self
    {
        return new self(TicimaxClient::for($storeKey));
    }

    public function getClient(): TicimaxClient
    {
        return $this->client;
    }

    /**
     * Bayi mağazasının tüm markalarını çek, ad → ID map'i döner.
     * Sonuç bir requesti boyunca cache'lenir (in-memory).
     */
    private ?array $brandCache = null;

    public function getBrandMap(): array
    {
        if ($this->brandCache !== null) {
            return $this->brandCache;
        }
        $resp = $this->client->call('product', 'SelectMarka', [
            'UyeKodu' => $this->client->getUyeKodu(),
        ]);
        $list = $resp->SelectMarkaResult->Marka ?? [];
        if (! is_array($list)) {
            $list = [$list];
        }
        $map = [];
        foreach ($list as $brand) {
            $name = trim((string) ($brand->Tanim ?? ''));
            $id = (int) ($brand->ID ?? 0);
            if ($name !== '' && $id > 0) {
                $map[mb_strtolower($name)] = $id;
            }
        }
        return $this->brandCache = $map;
    }

    /**
     * Bayi mağazasının tedarikçilerini getir, ad → ID map'i.
     */
    private ?array $supplierCache = null;

    public function getSupplierMap(): array
    {
        if ($this->supplierCache !== null) {
            return $this->supplierCache;
        }
        $resp = $this->client->call('product', 'SelectTedarikci', [
            'UyeKodu' => $this->client->getUyeKodu(),
        ]);
        $list = $resp->SelectTedarikciResult->Tedarikci ?? [];
        if (! is_array($list)) {
            $list = [$list];
        }
        $map = [];
        foreach ($list as $sup) {
            $name = trim((string) ($sup->Tanim ?? ''));
            $id = (int) ($sup->ID ?? 0);
            if ($name !== '' && $id > 0 && ! isset($map[mb_strtolower($name)])) {
                $map[mb_strtolower($name)] = $id;
            }
        }
        return $this->supplierCache = $map;
    }

    public function findOrCreateSupplierId(string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            return 0;
        }
        $map = $this->getSupplierMap();
        $key = mb_strtolower($name);
        if (isset($map[$key])) {
            return $map[$key];
        }
        $resp = $this->client->call('product', 'SaveTedarikci', [
            'UyeKodu' => $this->client->getUyeKodu(),
            'tedarikci' => ['ID' => 0, 'Tanim' => $name, 'Aktif' => true],
        ]);
        $newId = (int) ($resp->SaveTedarikciResult ?? 0);
        if ($newId > 0) {
            $this->supplierCache[$key] = $newId;
        }
        return $newId;
    }

    /**
     * Ticimax TedarikciID=0 kabul etmiyor — fallback için ilk geçerli tedarikçi.
     */
    public function getDefaultSupplierId(): int
    {
        $map = $this->getSupplierMap();
        return (int) (reset($map) ?: 0);
    }

    /**
     * Aynı şekilde MarkaID için fallback (zorunlu olduğunda).
     */
    public function getDefaultBrandId(): int
    {
        $map = $this->getBrandMap();
        return (int) (reset($map) ?: 0);
    }

    /**
     * Marka adına göre ID bul; yoksa SaveMarka ile yeni oluştur ve ID döner.
     */
    public function findOrCreateBrandId(string $name): int
    {
        $name = trim($name);
        if ($name === '') {
            return 0;
        }
        $map = $this->getBrandMap();
        $key = mb_strtolower($name);
        if (isset($map[$key])) {
            return $map[$key];
        }

        // Yeni marka oluştur
        $resp = $this->client->call('product', 'SaveMarka', [
            'UyeKodu' => $this->client->getUyeKodu(),
            'marka' => [
                'ID' => 0,
                'Tanim' => $name,
                'Aktif' => true,
                'Sira' => 0,
            ],
        ]);
        $newId = (int) ($resp->SaveMarkaResult ?? 0);
        if ($newId > 0) {
            $this->brandCache[$key] = $newId;
        }
        return $newId;
    }

    /**
     * Ticimax SelectUrun(UyeKodu, f: UrunFiltre, s: UrunSayfalama).
     * f filtre / s sayfalama ayrı yapılar.
     */
    /**
     * Ticimax DateTime alanları empty string kabul etmiyor — minimum DateTime kullanılır.
     * Bu, "filtre uygulama" anlamına gelir.
     */
    private const MIN_DATETIME = '0001-01-01T00:00:00';
    private const MAX_DATETIME = '9999-12-31T23:59:59';

    public function getNewProducts(?Carbon $since = null, int $page = 1, int $perPage = 50, string $sortDir = 'ASC'): array
    {
        $startIdx = max(0, ($page - 1) * $perPage);
        $params = [
            'UyeKodu' => $this->client->getUyeKodu(),
            'f' => $this->baseFilter() + [
                'DuzenlemeTarihiBaslangic' => $since ? $since->format('Y-m-d\TH:i:s') : self::MIN_DATETIME,
                'DuzenlemeTarihiBitis' => self::MAX_DATETIME,
            ],
            's' => [
                'BaslangicIndex' => $startIdx,
                'KayitSayisi' => $perPage,
                'KayitSayisinaGoreGetir' => true,
                'SiralamaDegeri' => 'ID',
                'SiralamaYonu' => $sortDir,
            ],
        ];
        $resp = $this->client->call('product', $this->method('select'), $params);
        return $this->normalizeList($resp, $this->method('select'), 'UrunKarti');
    }

    /**
     * Barkoda göre tek ürün — SelectUrun'a Barkod filtresi verir.
     */
    public function getProductByBarcode(string $barcode): ?array
    {
        $filter = $this->baseFilter() + ['Barkod' => $barcode];
        $params = [
            'UyeKodu' => $this->client->getUyeKodu(),
            'f' => $filter,
            's' => [
                'BaslangicIndex' => 0,
                'KayitSayisi' => 1,
                'KayitSayisinaGoreGetir' => true,
            ],
        ];
        try {
            $resp = $this->client->call('product', $this->method('select'), $params);
        } catch (\Throwable $e) {
            // Ticimax bayi servisinde bilinen bug: bulunamayan barkod için null ref fırlatıyor.
            // Bu durumu "barkod yok" olarak yorumla.
            if (str_contains($e->getMessage(), 'Value cannot be null') && str_contains($e->getMessage(), 'source')) {
                return null;
            }
            throw $e;
        }
        $list = $this->normalizeList($resp, $this->method('select'), 'UrunKarti');
        return $list[0] ?? null;
    }

    /**
     * UrunFiltre için varsayılan alanlar — Ticimax bazılarını zorunlu görüyor (DateTime, vb).
     */
    protected function baseFilter(): array
    {
        return [
            'Aktif' => -1,
            'Firsat' => -1,
            'Indirimli' => -1,
            // DateTime alanları min-max ile filtre uygulamayı kapatır
            'EklemeTarihiBaslangic' => self::MIN_DATETIME,
            'EklemeTarihiBitis' => self::MAX_DATETIME,
            'StokGuncellemeTarihiBaslangic' => self::MIN_DATETIME,
            'StokGuncellemeTarihiBitis' => self::MAX_DATETIME,
            'ResimEklemeTarihiBaslangic' => self::MIN_DATETIME,
            'ResimEklemeTarihiBitis' => self::MAX_DATETIME,
            'YayinTarihiBaslangic' => self::MIN_DATETIME,
            'YayinTarihiBitis' => self::MAX_DATETIME,
        ];
    }

    /**
     * SaveUrun: UrunKarti listesi + UrunKartiAyar + VaryasyonAyar gönderilir.
     * Tek ürün için bile array'e sarıp gönderiyoruz.
     */
    public function createProduct(array $urunKarti): array
    {
        return $this->saveBatch([$urunKarti], $this->fullCreateAyarlari())[0] ?? [];
    }

    public function updateProduct(string $productId, array $urunKarti): array
    {
        $urunKarti['ID'] = (int) $productId;
        return $this->saveBatch([$urunKarti], $this->fullUpdateAyarlari())[0] ?? [];
    }

    /**
     * Birden fazla ürünü tek SaveUrun çağrısında gönderir.
     *
     * @throws \RuntimeException SaveUrunResult 0 dönerse — Ticimax sessizce reddediyor demektir.
     */
    public function saveBatch(array $urunKartlari, array $ayarlar = []): array
    {
        $ayarlar = array_replace_recursive($this->fullCreateAyarlari(), $ayarlar);
        $params = [
            'UyeKodu' => $this->client->getUyeKodu(),
            'urunKartlari' => $urunKartlari,
            'ukAyar' => $ayarlar['ukAyar'],
            'vAyar' => $ayarlar['vAyar'],
        ];
        $resp = $this->client->call('product', $this->method('save'), $params);

        // SaveUrunResult: 1+ = başarılı (kayıt sayısı), 0 = sessiz başarısız (Ticimax error message vermez)
        $saveResult = is_object($resp) ? ($resp->SaveUrunResult ?? null) : null;
        if ($saveResult !== null && (int) $saveResult <= 0) {
            $barcodes = array_filter(array_map(
                fn ($u) => $u['Varyasyonlar'][0]['Barkod'] ?? ($u['Varyasyonlar']['Varyasyon']['Barkod'] ?? '?'),
                $urunKartlari
            ));
            throw new \RuntimeException(
                'Ticimax SaveUrun başarısız (SaveUrunResult=' . (int) $saveResult . '). ' .
                'Barkodlar: ' . implode(', ', $barcodes) . '. ' .
                'Olası sebepler: zorunlu alan eksik, ücretsiz hesap limiti, varyasyon barkod çakışması, kategori bayi\'de yok.'
            );
        }

        return $this->normalizeList($resp, $this->method('save'), 'UrunKarti');
    }

    /**
     * StokAdediGuncelle gerçek imzası: (UyeKodu, ArrayOfVaryasyon urunler).
     * Varyasyon ID'si bilinmiyorsa Barkod ile de eşleştirmeye çalışır.
     */
    public function updateStock(string $varyasyonId, int $stock, ?string $barkod = null): array
    {
        $varyasyon = ['ID' => (int) $varyasyonId, 'StokAdedi' => $stock];
        if ($barkod !== null) {
            $varyasyon['Barkod'] = $barkod;
        }
        $params = [
            'UyeKodu' => $this->client->getUyeKodu(),
            'urunler' => [$varyasyon],
        ];
        $resp = $this->client->call('product', $this->method('update_stock'), $params);
        return $this->normalizeOne($resp) ?? [];
    }

    /**
     * UpdateUrunFiyat gerçek imzası: (UyeKodu, ArrayOfUpdateUrunFiyat request, UpdateUrunFiyatAyar ayar).
     * Barkoda göre eşleştir, fiyatları güncelle.
     */
    public function updatePrice(string $barkod, float $price, float $kdvOrani = 20.0, bool $kdvDahil = true): array
    {
        $params = [
            'UyeKodu' => $this->client->getUyeKodu(),
            'request' => [[
                'Barkod' => $barkod,
                'Fiyatlar' => [
                    'SatisFiyati' => $price,
                    'IndirimliFiyat' => 0,
                    'KDVDahil' => $kdvDahil,
                    'KdvOrani' => (int) $kdvOrani,
                    'UyeTipiFiyat1' => 0,
                    'UyeTipiFiyat2' => 0,
                    'UyeTipiFiyat3' => 0,
                    'UyeTipiFiyat4' => 0,
                    'UyeTipiFiyat5' => 0,
                ],
                'TedarikciKodu' => '',
                'TedarikciKodu2' => '',
                'UrunIds' => '',
                'UrunKartiIds' => '',
            ]],
            'ayar' => [
                'BarkodKodunaGoreGuncelle' => true,
                'IndirimliFiyatGuncelle' => false,
                'TedarikciKodu2GoreGuncelle' => false,
                'TedarikciKodunaGoreGuncelle' => false,
                'UrunIdGoreGuncelle' => false,
                'UrunKartiIdGoreGuncelle' => false,
                'UyeTipiFiyat1Guncelle' => false,
                'UyeTipiFiyat2Guncelle' => false,
                'UyeTipiFiyat3Guncelle' => false,
                'UyeTipiFiyat4Guncelle' => false,
                'UyeTipiFiyat5Guncelle' => false,
                'VaryasyonGuncelle' => true,
            ],
        ];
        $resp = $this->client->call('product', $this->method('update_price'), $params);
        return $this->normalizeOne($resp) ?? [];
    }

    /**
     * Stok + fiyat — iki ayrı SOAP çağrısı.
     * $varyasyonId stok güncellemesi için (bayi tarafının Varyasyon.ID'si).
     * $barkod fiyat güncellemesi için (cross-store eşleşme).
     */
    public function updateStockAndPrice(string $varyasyonId, int $stock, float $price, ?string $barkod = null): array
    {
        return [
            'stock' => $this->updateStock($varyasyonId, $stock, $barkod),
            'price' => $barkod ? $this->updatePrice($barkod, $price) : null,
        ];
    }

    /**
     * UrunKarti.Aktif alanını güncellemek için SaveUrun ile sadece Aktif update.
     */
    public function setActive(string $productId, bool $active): array
    {
        return $this->updateProduct($productId, ['Aktif' => $active]);
    }

    /**
     * Tam ürün oluşturmada hangi alanların yazılacağı (UrunKartiAyar + VaryasyonAyar).
     * Hepsini true yaparak gönderdiğimiz tüm alanların yazılmasını söylüyoruz.
     */
    protected function fullCreateAyarlari(): array
    {
        return [
            'ukAyar' => [
                'AciklamaGuncelle' => true,
                'AktifGuncelle' => true,
                // Kategori ID'leri mağazalar arasında farklı; kapalı tutuyoruz.
                'AnaKategoriGuncelle' => false,
                'AramaAnahtarKelimeGuncelle' => true,
                'EtiketGuncelle' => false,
                'KategoriGuncelle' => false,
                'ListedeGosterGuncelle' => true,
                // Marka brand-resolver ile ada göre eşlenir; güncelleme açık.
                'MarkaGuncelle' => true,
                'OnYaziGuncelle' => true,
                'ResimOlmayanlaraResimEkle' => true,
                'SatisBirimiGuncelle' => true,
                'SeoAnahtarKelimeGuncelle' => true,
                'SeoSayfaAciklamaGuncelle' => true,
                'SeoSayfaBaslikGuncelle' => true,
                'OncekiResimleriSil' => false,
                'Base64Resim' => false,
                'ResimleriIndirme' => false,
            ],
            'vAyar' => [
                'AktifGuncelle' => true,
                'AlisFiyatiGuncelle' => true,
                'BarkodGuncelle' => true,
                'IndirimliFiyatiGuncelle' => true,
                'KdvDahilGuncelle' => true,
                'KdvOraniGuncelle' => true,
                'ParaBirimiGuncelle' => true,
                'PiyasaFiyatiGuncelle' => true,
                'SatisFiyatiGuncelle' => true,
                'StokAdediGuncelle' => true,
                'StokKoduGuncelle' => true,
                'UrunKartiAktifGuncelle' => true,
                'OncekiResimleriSil' => false,
            ],
        ];
    }

    protected function fullUpdateAyarlari(): array
    {
        return $this->fullCreateAyarlari();
    }

    protected function method(string $key): string
    {
        return (string) config("ticimax.methods.product.{$key}");
    }

    /**
     * Result'tan UrunKarti listesini çıkarır. Ticimax tek elemanı obje, çoklu elemanları array
     * dönebiliyor. ArrayOfUrunKarti wrapper'ı UrunKarti anahtarı ile geliyor.
     */
    protected function normalizeList(mixed $resp, string $method = '', string $itemKey = 'UrunKarti'): array
    {
        $resultKey = $method . 'Result';
        if (is_object($resp)) {
            // SaveUrun gibi method'larda Result alanı sadece status int olabilir.
            // Önce urunKartlari (gerçek payload) varsa onu kullan; yoksa Result alanına bak.
            if (isset($resp->urunKartlari)) {
                $resp = $resp->urunKartlari;
            } elseif (isset($resp->{$resultKey}) && (is_object($resp->{$resultKey}) || is_array($resp->{$resultKey}))) {
                $resp = $resp->{$resultKey};
            }
        }
        if (is_object($resp)) {
            $resp = (array) $resp;
        }
        if (! is_array($resp)) {
            return [];
        }
        if (isset($resp[$itemKey])) {
            $resp = is_array($resp[$itemKey]) && array_is_list($resp[$itemKey]) ? $resp[$itemKey] : [$resp[$itemKey]];
        } elseif (! array_is_list($resp)) {
            $resp = [$resp];
        }
        return array_map(fn ($r) => $this->toArray($r), $resp);
    }

    protected function normalizeOne(mixed $resp): ?array
    {
        if (! $resp) {
            return null;
        }
        return $this->toArray($resp);
    }

    protected function toArray(mixed $v): array
    {
        if (is_array($v)) {
            return array_map(fn ($x) => is_object($x) || is_array($x) ? $this->toArray($x) : $x, $v);
        }
        if (is_object($v)) {
            return $this->toArray((array) $v);
        }
        return [];
    }
}
