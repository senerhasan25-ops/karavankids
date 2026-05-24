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
     * Bayi'nin kategorilerini ID listesi olarak getir; SaveUrun'da yeni urun yaratmak icin
     * en az 1 kategori zorunlu (bos Kategoriler -> SaveUrunResult=0 sessiz red).
     * "Kategorisiz" varsa onu, yoksa ilk kategoriyi default kabul eder.
     */
    private ?int $defaultCategoryCache = null;

    public function getDefaultCategoryId(): int
    {
        if ($this->defaultCategoryCache !== null) {
            return $this->defaultCategoryCache;
        }
        try {
            $resp = $this->client->call('product', 'SelectKategori', [
                'UyeKodu' => $this->client->getUyeKodu(),
            ]);
            $list = $resp->SelectKategoriResult->Kategori ?? [];
            if (! is_array($list)) {
                $list = [$list];
            }
            // Once "Kategorisiz" / "Diger" gibi default arayan, sonra ilk geceni al
            foreach ($list as $kat) {
                $name = mb_strtolower(trim((string) ($kat->Tanim ?? '')));
                if (in_array($name, ['kategorisiz', 'diğer', 'diger', 'genel'])) {
                    return $this->defaultCategoryCache = (int) ($kat->ID ?? 0);
                }
            }
            $first = $list[0] ?? null;
            return $this->defaultCategoryCache = (int) ($first->ID ?? 0);
        } catch (\Throwable) {
            return $this->defaultCategoryCache = 0;
        }
    }

    /**
     * Magazadaki tum kategorileri ID->{ID,PID,Tanim} map'i olarak getir.
     * Ana magazadan ag aci cikarmak ve bayide ayni agaci kurmak icin kullanilir.
     */
    private ?array $categoryTreeCache = null;

    public function getCategoryTree(): array
    {
        if ($this->categoryTreeCache !== null) {
            return $this->categoryTreeCache;
        }
        try {
            $resp = $this->client->call('product', 'SelectKategori', [
                'UyeKodu' => $this->client->getUyeKodu(),
            ]);
            $list = $resp->SelectKategoriResult->Kategori ?? [];
            if (! is_array($list)) {
                $list = [$list];
            }
            $tree = [];
            foreach ($list as $kat) {
                $id = (int) ($kat->ID ?? 0);
                if ($id > 0) {
                    $tree[$id] = [
                        'ID' => $id,
                        'PID' => (int) ($kat->PID ?? 0),
                        'Tanim' => trim((string) ($kat->Tanim ?? '')),
                    ];
                }
            }
            return $this->categoryTreeCache = $tree;
        } catch (\Throwable) {
            return $this->categoryTreeCache = [];
        }
    }

    /**
     * Bayide ad+parent eslesmesi yap; yoksa SaveKategori ile o parent altinda olustur.
     * Index: "${parentId}|${lowercase_name}" → bayi kategori ID. Ag aci derinligi onemli degil,
     * her caginnda (parent_bayi_id, name) ile cagrilir.
     */
    private array $categoryByNameParentCache = [];

    public function findOrCreateCategoryByNameAndParent(string $name, int $bayiParentId): int
    {
        $name = trim($name);
        if ($name === '') {
            return 0;
        }
        $cacheKey = $bayiParentId . '|' . mb_strtolower($name);
        if (isset($this->categoryByNameParentCache[$cacheKey])) {
            return $this->categoryByNameParentCache[$cacheKey];
        }

        // Once mevcut bayi agacindan eslesen kategoriyi bul
        foreach ($this->getCategoryTree() as $kat) {
            if ((int) $kat['PID'] === $bayiParentId
                && mb_strtolower($kat['Tanim']) === mb_strtolower($name)) {
                return $this->categoryByNameParentCache[$cacheKey] = (int) $kat['ID'];
            }
        }

        // Yok — bu parent altinda yeni kategori olustur
        try {
            $resp = $this->client->call('product', 'SaveKategori', [
                'UyeKodu' => $this->client->getUyeKodu(),
                'kategori' => [
                    'ID' => 0,
                    'PID' => $bayiParentId,
                    'Aktif' => true,
                    'Tanim' => $name,
                    'Sira' => 999,
                    'KategoriMenuGoster' => true,
                ],
            ]);
            $newId = (int) ($resp->SaveKategoriResult ?? 0);
            if ($newId > 0) {
                // Cache'i guncelle: hem name+parent indexi hem agac
                $this->categoryByNameParentCache[$cacheKey] = $newId;
                if ($this->categoryTreeCache !== null) {
                    $this->categoryTreeCache[$newId] = ['ID' => $newId, 'PID' => $bayiParentId, 'Tanim' => $name];
                }
            }
            return $newId;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Ana magazadaki bir kategorinin AGAC YOLUNU bayide aynalar.
     *  - Ana agacta root'tan asagi yola (path) cikar.
     *  - Her node icin bayide findOrCreateCategoryByNameAndParent ile karsiligi kur.
     *  - Son node'un (leaf) bayi ID'sini doner.
     * Performans icin sonuc (ana_cat_id → bayi_cat_id) in-memory cache'lenir.
     *
     * @param  int    $anaCategoryId  ana magazadaki kategori ID'si
     * @param  array  $anaTree        ana magazadan getCategoryTree() ciktisi (cagiri tarafindan verilir)
     * @return int                    bayi'deki karsilik ID, kurulamazsa 0
     */
    private array $anaToBayiCatCache = [];

    public function mirrorCategoryFromAna(int $anaCategoryId, array $anaTree): int
    {
        if ($anaCategoryId <= 0 || empty($anaTree[$anaCategoryId])) {
            return 0;
        }
        if (isset($this->anaToBayiCatCache[$anaCategoryId])) {
            return $this->anaToBayiCatCache[$anaCategoryId];
        }

        // Ana agacta root'tan asagi yolu cikar
        $path = [];
        $cur = $anaTree[$anaCategoryId];
        $safety = 32; // sonsuz dongu koruma
        while ($cur && $safety-- > 0) {
            array_unshift($path, $cur);
            $pid = (int) ($cur['PID'] ?? 0);
            $cur = $pid > 0 ? ($anaTree[$pid] ?? null) : null;
        }

        // Yolu adim adim bayide kur
        $bayiParent = 0;
        $bayiId = 0;
        foreach ($path as $node) {
            $bayiId = $this->findOrCreateCategoryByNameAndParent($node['Tanim'], $bayiParent);
            if ($bayiId === 0) {
                break; // bayide olusturulamadi
            }
            $bayiParent = $bayiId;
        }
        return $this->anaToBayiCatCache[$anaCategoryId] = $bayiId;
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
     * StokKodu ile aktif tek ürünü çek; ilk varyasyonun ID'sini döndürür.
     * Sipariş aktarımı için kullanılır (siparis_aktar.py:get_target_product_id pattern).
     *
     * @return int|null Varyasyon.ID (sipariş line'ında UrunID olarak yazılır), bulunamazsa null
     */
    public function getVariantIdByStokKodu(string $stokKodu): ?int
    {
        $stokKodu = trim($stokKodu);
        if ($stokKodu === '') {
            return null;
        }
        $filter = $this->baseFilter() + ['StokKodu' => $stokKodu, 'Aktif' => 1];
        $params = [
            'UyeKodu' => $this->client->getUyeKodu(),
            'f' => $filter,
            's' => [
                'BaslangicIndex' => 0,
                'KayitSayisi' => 1,
                'KayitSayisinaGoreGetir' => true,
                'SiralamaDegeri' => 'ID',
                'SiralamaYonu' => 'ASC',
            ],
        ];
        try {
            $resp = $this->client->call('product', $this->method('select'), $params);
        } catch (\Throwable $e) {
            // Ticimax bayi servisindeki "Value cannot be null (source)" bug'ı = bulunamadı.
            if (str_contains($e->getMessage(), 'Value cannot be null') && str_contains($e->getMessage(), 'source')) {
                return null;
            }
            throw $e;
        }
        $list = $this->normalizeList($resp, $this->method('select'), 'UrunKarti');
        if (empty($list)) {
            return null;
        }
        $first = $list[0];
        $varyasyonlar = $first['Varyasyonlar'] ?? [];
        if (isset($varyasyonlar['Varyasyon'])) {
            $varyasyonlar = is_array($varyasyonlar['Varyasyon']) && array_is_list($varyasyonlar['Varyasyon'])
                ? $varyasyonlar['Varyasyon']
                : [$varyasyonlar['Varyasyon']];
        }
        $first = $varyasyonlar[0] ?? null;
        $id = $first ? (int) ($first['ID'] ?? 0) : 0;
        return $id > 0 ? $id : null;
    }

    /**
     * StokKodu ile TÜM eşleşen ürünler — dedupe komutu için.
     * Aynı StokKodu'na sahip birden fazla ürün varsa hepsini döner.
     */
    public function findAllProductsByStokKodu(string $stokKodu, int $limit = 10): array
    {
        $stokKodu = trim($stokKodu);
        if ($stokKodu === '') {
            return [];
        }
        $filter = $this->baseFilter() + ['StokKodu' => $stokKodu];
        $params = [
            'UyeKodu' => $this->client->getUyeKodu(),
            'f' => $filter,
            's' => [
                'BaslangicIndex' => 0,
                'KayitSayisi' => $limit,
                'KayitSayisinaGoreGetir' => true,
                'SiralamaDegeri' => 'ID',
                'SiralamaYonu' => 'ASC',
            ],
        ];
        try {
            $resp = $this->client->call('product', $this->method('select'), $params);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), 'Value cannot be null') && str_contains($e->getMessage(), 'source')) {
                return [];
            }
            throw $e;
        }
        return $this->normalizeList($resp, $this->method('select'), 'UrunKarti');
    }

    /**
     * StokKodu ile tek ürün — SelectUrun'a StokKodu filtresi verir, full UrunKarti döner.
     * Lokal mapping doldururken kullanılır: tek SOAP çağrısı ile hem UrunKartiID hem
     * tüm Varyasyon.ID'leri tek seferde alınır.
     */
    public function getProductByStokKodu(string $stokKodu): ?array
    {
        $stokKodu = trim($stokKodu);
        if ($stokKodu === '') {
            return null;
        }
        $filter = $this->baseFilter() + ['StokKodu' => $stokKodu];
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
            if (str_contains($e->getMessage(), 'Value cannot be null') && str_contains($e->getMessage(), 'source')) {
                return null;
            }
            throw $e;
        }
        $list = $this->normalizeList($resp, $this->method('select'), 'UrunKarti');
        return $list[0] ?? null;
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

        // SaveUrunResult anlamı:
        //   N > 0  → N yeni ürün oluşturuldu
        //   0      → YENİ ürün oluşturulmadı (mevcut güncellendi VEYA gerçekten patladı)
        //
        // Result=0 hem update-success hem de fail demek olabiliyor. Bu yüzden:
        // response'ta `urunKartlari` echo geliyorsa (yani Ticimax payload'ı işledi
        // ve ürün verisini geri döndü) → başarılı say.
        // Eğer urunKartlari boş/yoksa → gerçek hata.
        $saveResult = is_object($resp) ? ($resp->SaveUrunResult ?? null) : null;
        $echoedKartlar = $this->normalizeList($resp, $this->method('save'), 'UrunKarti');

        if ($saveResult !== null && (int) $saveResult <= 0 && empty($echoedKartlar)) {
            // Ne yeni oluştu ne update echo geldi → gerçek hata
            $barcodes = array_filter(array_map(
                fn ($u) => $u['Varyasyonlar'][0]['Barkod'] ?? ($u['Varyasyonlar']['Varyasyon']['Barkod'] ?? '?'),
                $urunKartlari
            ));
            throw new \RuntimeException(
                'Ticimax SaveUrun başarısız (SaveUrunResult=0, urunKartlari boş). ' .
                'Barkodlar: ' . implode(', ', $barcodes) . '. ' .
                'Olası sebepler: zorunlu alan eksik, ücretsiz hesap limiti, varyasyon barkod çakışması, kategori bayi\'de yok.'
            );
        }

        return $echoedKartlar;
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
                // Lokal-öncelikli akışa geçtik: eşleşmeyi biz lokal mapping tablosundan
                // çözüp payload'a ID yazıyoruz. TedarikciKodu match'i artık güvenlik
                // kemeri olarak gerekmiyor (yanlış prefix'li eski ürünleri yakalamasın).
                'TedarikciKodunaGoreGuncelle' => false,
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
                'TedarikciKodunaGoreGuncelle' => false,
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
