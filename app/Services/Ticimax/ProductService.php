<?php

namespace App\Services\Ticimax;

use App\Models\SyncSetting;
use Illuminate\Support\Carbon;

class ProductService
{
    public function __construct(protected TicimaxClient $client) {}

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
        $cacheKey = $bayiParentId.'|'.mb_strtolower($name);
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
     * @param  int  $anaCategoryId  ana magazadaki kategori ID'si
     * @param  array  $anaTree  ana magazadan getCategoryTree() ciktisi (cagiri tarafindan verilir)
     * @return int bayi'deki karsilik ID, kurulamazsa 0
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
        return $this->getProductsByDateFilter('DuzenlemeTarihiBaslangic', 'DuzenlemeTarihiBitis', $since, $page, $perPage, $sortDir);
    }

    /**
     * SADECE YENİ EKLENEN ürünleri çek (EklemeTarihiBaslangic filtresi).
     * Mevcut ürün üzerinde sonradan yapılan düzenleme YAKALANMAZ —
     * yalnızca o tarihten sonra ilk kez eklenmiş kartlar gelir.
     *
     * Kullanım: SyncNewProductsJob (yeni ürünleri ana → bayi mirror).
     * Avantaj: çok daha dar sonuç → daha az SOAP, çok daha hızlı.
     */
    public function getProductsByCreated(?Carbon $since = null, int $page = 1, int $perPage = 50, string $sortDir = 'ASC'): array
    {
        return $this->getProductsByDateFilter('EklemeTarihiBaslangic', 'EklemeTarihiBitis', $since, $page, $perPage, $sortDir);
    }

    /**
     * Fiyat VEYA stok değişen ürünleri çek (FiyatStokGuncellemeTarihiBas filtresi).
     * Açıklama, resim, kategori vb. değişiklikleri YAKALANMAZ — sadece fiyat/stok.
     *
     * Kullanım: SyncStockPriceJob (delta stok/fiyat sync).
     * Avantaj: sadece ilgili değişiklikler → DB karşılaştırması da daha az iş.
     */
    public function getProductsByStockOrPriceChanged(?Carbon $since = null, int $page = 1, int $perPage = 50, string $sortDir = 'ASC'): array
    {
        // DİKKAT: Doğru alan StokGuncellemeTarihiBaslangic (canlı doğrulandı).
        // Eski 'FiyatStokGuncellemeTarihiBas' Ticimax tarafında yok sayılıyordu.
        return $this->getProductsByDateFilter('StokGuncellemeTarihiBaslangic', 'StokGuncellemeTarihiBitis', $since, $page, $perPage, $sortDir);
    }

    /**
     * Tarih filtresi → ürün çekme. Filtre tipine göre alan adları belirlenir.
     *
     * @param  string  $filterType  'created' | 'stock_price' | 'modified'
     */
    protected function getProductsByDateFilter(string $startField, string $endField, ?Carbon $since, int $page, int $perPage, string $sortDir): array
    {
        $startIdx = max(0, ($page - 1) * $perPage);

        return $this->fetchProductPage($startField, $endField, $since, $startIdx, $perPage, $sortDir);
    }

    /**
     * Tek bir ürün sayfasını mutlak offset (BaslangicIndex) ile çek.
     * Recovery alt-sayfalaması bunu farklı offset/perPage ile defalarca çağırır.
     */
    protected function fetchProductPage(string $startField, string $endField, ?Carbon $since, int $startIdx, int $perPage, string $sortDir): array
    {
        $params = [
            'UyeKodu' => $this->client->getUyeKodu(),
            // DİKKAT: array_merge kullan, `+` DEĞİL. baseFilter() zaten
            // EklemeTarihiBaslangic/Bitis anahtarlarını MIN/MAX ile içeriyor;
            // `+` birleşiminde çakışan anahtarda SOLDAKI (baseFilter) kazanır ve
            // tarih override'ı sessizce ATILIRDI → created filtresi hep MIN kalıp
            // TÜM kataloğu döndürüyordu (delta sync çalışmıyordu). array_merge ile
            // sağdaki (gerçek tarih) kazanır.
            'f' => array_merge($this->baseFilter(), [
                $startField => $since ? $since->format('Y-m-d\TH:i:s') : self::MIN_DATETIME,
                $endField => self::MAX_DATETIME,
            ]),
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

    /** Filtre tipi → [startField, endField] eşlemesi (recovery için tek kaynak). */
    // Canlı doğrulanmış alan adları (2099 testi, debug-stock-filter-field.php):
    //   EklemeTarihiBaslangic           → filtreliyor ✓ (yeni eklenen)
    //   StokGuncellemeTarihiBaslangic   → filtreliyor ✓ (stok/fiyat değişimi)
    //   DuzenlemeTarihiBaslangic        → filtreliyor ✓ (herhangi düzenleme)
    //   FiyatStokGuncellemeTarihiBas    → Ticimax YOK SAYIYOR ✗ (eski yanlış ad)
    private const FILTER_FIELDS = [
        'created' => ['EklemeTarihiBaslangic', 'EklemeTarihiBitis'],
        'stock_price' => ['StokGuncellemeTarihiBaslangic', 'StokGuncellemeTarihiBitis'],
        'modified' => ['DuzenlemeTarihiBaslangic', 'DuzenlemeTarihiBitis'],
    ];

    /**
     * Bir ürün sayfasını çek; Ticimax pagination bug'ına denk gelirse SAYFAYI
     * KÜÇÜK DİLİMLERE bölerek kurtarmaya çalış (sıfır-kayıp hedefi, #2).
     *
     * Ticimax SOAP'ı belirli BaslangicIndex aralıklarında (~1940-2030, sabit
     * pencere) "Value cannot be null. Parameter name: source" fırlatıyor. Bu
     * "veri sonu" DEĞİL. Düz skip yaparsak o sayfadaki ~100 ürünü tamamen
     * kaybederiz. Bunun yerine sayfayı $subStep'lik dilimlere ayırıp her dilimi
     * ayrı offset ile çekeriz: yalnızca offset'i bug penceresine düşen küçük
     * dilimler kaybolur (~30-40 ürün), gerisi kurtarılır.
     *
     * @return array{products: array, bug: bool, recovered: int, lost: int}
     *                                                                      - bug=false → normal sayfa (products dolu) veya gerçek son (products boş)
     *                                                                      - bug=true  → sayfa bug'a denk geldi; products = kurtarılan alt küme,
     *                                                                      recovered/lost = kurtarılan/kaybedilen tahmini ürün sayısı
     */
    public function fetchProductPageRecovering(string $filterType, ?Carbon $since, int $page, int $perPage, string $sortDir = 'ASC', int $subStep = 10): array
    {
        [$startField, $endField] = self::FILTER_FIELDS[$filterType]
            ?? throw new \InvalidArgumentException("Bilinmeyen filtre tipi: {$filterType}");

        $startIdx = max(0, ($page - 1) * $perPage);

        // Önce normal (tam sayfa) dene — mutlu yol, ek SOAP yok.
        try {
            $products = $this->fetchProductPage($startField, $endField, $since, $startIdx, $perPage, $sortDir);

            return ['products' => $products, 'bug' => false, 'recovered' => count($products), 'lost' => 0];
        } catch (\Throwable $e) {
            if (! $this->isTicimaxPaginationBug($e)) {
                throw $e; // gerçek hata → çağırana bırak
            }
        }

        // Bug penceresine denk geldik → sayfayı $subStep'lik dilimlerle tara.
        $recovered = [];
        $seenIds = [];
        $lostSingles = 0;

        $append = function (array $slice) use (&$recovered, &$seenIds) {
            foreach ($slice as $p) {
                $id = (string) ($p['ID'] ?? '');
                if ($id !== '' && isset($seenIds[$id])) {
                    return; // güvenlik: çakışma olmaz ama yine de dedupe
                }
                if ($id !== '') {
                    $seenIds[$id] = true;
                }
                $recovered[] = $p;
            }
        };

        for ($off = $startIdx; $off < $startIdx + $perPage; $off += $subStep) {
            try {
                $append($this->fetchProductPage($startField, $endField, $since, $off, $subStep, $sortDir));
            } catch (\Throwable $e) {
                if (! $this->isTicimaxPaginationBug($e)) {
                    throw $e;
                }
                // Dilim de bug'a düştü → TEK TEK (size-1) kurtarmayı dene. Ticimax bug'ı
                // belirli offset'lere özgü olduğundan, dilimdeki diğer ürünler genelde
                // sağlamdır; yalnızca gerçekten bozuk tekil ürün(ler) kaybedilir
                // (Gemini'nin Python kodundaki size-1 fallback ile aynı yaklaşım).
                for ($o2 = $off; $o2 < $off + $subStep; $o2++) {
                    try {
                        $append($this->fetchProductPage($startField, $endField, $since, $o2, 1, $sortDir));
                    } catch (\Throwable $e2) {
                        if ($this->isTicimaxPaginationBug($e2)) {
                            $lostSingles++; // sadece bu TEK ürün gerçekten bozuk

                            continue;
                        }
                        throw $e2;
                    }
                }
            }
        }

        return [
            'products' => $recovered,
            'bug' => true,
            'recovered' => count($recovered),
            'lost' => $lostSingles,
        ];
    }

    /**
     * Ticimax SelectUrun pagination'da belirli BaslangicIndex aralıklarında
     * "Value cannot be null. Parameter name: source" fırlatıyor (Ticimax kendi
     * LINQ'inde null dereference). Test edildi: 1950, 2000 patlar; 2050 normal
     * 100 ürün döner, ÖTESİ devam eder. Yani bu "veri sonu" DEĞİL, geçici bug.
     *
     * Bu yardımcı, Ticimax bug'ı tetiklendiğinde bu hatayı yakalayıp:
     *   - true  döner (skip & continue — pagination loop bir sonraki sayfaya geçsin)
     *   - false döner (gerçek hata — throw edilsin)
     *
     * Job loop'u bunu görüp `$page++` yapar, `continue` eder; veri kaybı
     * sadece bu spesifik sayfa kadar olur (~50 ürün), ÖTESİNDEKİ binlerce
     * ürünü kaçırmaz.
     */
    public function isTicimaxPaginationBug(\Throwable $e): bool
    {
        $msg = (string) $e->getMessage();

        return stripos($msg, 'Value cannot be null') !== false
            && stripos($msg, 'source') !== false;
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
    /**
     * StokKodu LIKE (kismi) veya coklu (virgulle ayrilmis) ile urun listesi.
     * - Virgul varsa: her bir kodu birebir cek, birlestir.
     * - Tek deger: Ticimax StokKodu filtresi cogu kez birebir esler — once birebir,
     *   sonra SelectUrun'a buyuk sayfa cek + sunucu tarafinda LIKE filtrele.
     *
     * @param  string  $query  Tek StokKodu, virgulle ayrilmis liste veya kismi ifade
     * @param  int  $maxResults  Tek-deger LIKE icin tarama ust limiti (varsayilan 200)
     * @return array UrunKarti list
     */
    public function searchProductsByStokKodu(string $query, int $maxResults = 200): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        // Coklu (virgul) — her parcayi birebir cek
        if (str_contains($query, ',')) {
            $parts = array_filter(array_map('trim', explode(',', $query)));
            $out = [];
            $seen = [];
            foreach ($parts as $p) {
                $hit = $this->getProductByStokKodu($p);
                if ($hit && ! isset($seen[(int) ($hit['ID'] ?? 0)])) {
                    $seen[(int) ($hit['ID'] ?? 0)] = true;
                    $out[] = $hit;
                }
            }

            return $out;
        }

        // Tek deger: once birebir dene
        $exact = $this->getProductByStokKodu($query);
        if ($exact) {
            return [$exact];
        }

        // Birebir yok: ust seviyede ilk $maxResults uruncu tara + PHP tarafinda LIKE
        $qLower = mb_strtolower($query);
        $found = [];
        $page = 1;
        $perPage = 100;
        $scanned = 0;
        while ($scanned < $maxResults) {
            $batch = $this->getNewProducts(null, $page, $perPage, 'DESC');
            if (empty($batch)) {
                break;
            }
            foreach ($batch as $p) {
                $scanned++;
                $variants = $p['Varyasyonlar']['Varyasyon'] ?? $p['Varyasyonlar'] ?? [];
                if (isset($variants['Barkod'])) {
                    $variants = [$variants];
                }
                $matched = false;
                foreach ($variants as $v) {
                    $sk = mb_strtolower(trim((string) ($v['StokKodu'] ?? '')));
                    if ($sk !== '' && str_contains($sk, $qLower)) {
                        $matched = true;
                        break;
                    }
                }
                if ($matched) {
                    $found[] = $p;
                }
                if ($scanned >= $maxResults) {
                    break;
                }
            }
            if (count($batch) < $perPage) {
                break;
            }
            $page++;
        }

        return $found;
    }

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
     * Tedarikçi koduna göre tek ürün — SelectUrun'a TedarikciKodu filtresi verir.
     * Eşleme/upsert birincil anahtarı bu (stok_kodu/barkod çoklu olabildiği için).
     */
    public function getProductByTedarikciKodu(string $tedarikciKodu): ?array
    {
        $tedarikciKodu = trim($tedarikciKodu);
        if ($tedarikciKodu === '') {
            return null;
        }
        $filter = $this->baseFilter() + ['TedarikciKodu' => $tedarikciKodu];
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
     * UrunKartiID ile tek ürün — SelectUrun'a UrunKartiID filtresi verir.
     *
     * ÖNEMLİ: baseFilter() hiç tarih alanı göndermez → bu çağrı TARİH FİLTRESİZ'dir.
     * Delta fetch'lerinin aksine (StokGuncellemeTarihi/EklemeTarihi filtresi Ticimax'te
     * kimi kartı VARYASYONSUZ veya TedarikciKodu BOŞ döndürüyor — "stripping"), ID ile
     * tekil çekim kartı TAM döndürür: gerçek TedarikciKodu kart + varyasyon seviyesinde
     * gelir. Stripli delta kartının gerçek tedarikçi kodunu kurtarmak için kullanılır.
     */
    public function getProductById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $filter = $this->baseFilter() + ['UrunKartiID' => $id];
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
     * UrunFiltre için varsayılan alanlar.
     *
     * DİKKAT: Eskiden burada TÜM tarih alanları (Ekleme/Stok/Resim/Yayin) MIN-MAX
     * ile gönderiliyordu; niyet "filtre kapalı" idi. Ama Ticimax bunları
     * `tarih >= MIN AND tarih <= MAX` olarak uyguluyor ve ilgili tarih alanı NULL
     * olan ürünleri ELİYOR. Özellikle RESMİ OLMAYAN ürünlerin ResimEklemeTarihi
     * NULL olduğundan ~505 ürün listeden düşüyordu (canlı doğrulandı:
     * ResimEklemeTarihi MIN/MAX → 3047, alansız → 3552).
     *
     * Çözüm: hiçbir tarih alanını VARSAYILAN olarak gönderme. Gerçek delta filtreleri
     * (fetchProductPage) yalnızca ihtiyaç duydukları TEK tarih alanını array_merge ile
     * ekler. (Gemini'nin Python sayım kodu da yalnızca {Aktif} gönderip doğru sayıyor.)
     */
    protected function baseFilter(): array
    {
        return [
            'Aktif' => -1,
            'Firsat' => -1,
            'Indirimli' => -1,
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
                'Ticimax SaveUrun başarısız (SaveUrunResult=0, urunKartlari boş). '.
                'Barkodlar: '.implode(', ', $barcodes).'. '.
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

    /** Üye tipi 1-5 iskonto oranları (sync_settings'ten okunur) — process-içi cache. */
    protected static ?array $uyeTipiIskonto = null;

    /** Varsayılan iskonto oranları (% — kullanıcı panelden değiştirmemişse). */
    public const UYE_TIPI_VARSAYILAN = [1 => 35.0, 2 => 30.0, 3 => 40.0, 4 => 25.0, 5 => 20.0];

    /** sync_settings anahtarı — üye tipi iskonto oranları JSON. */
    public const UYE_TIPI_ISKONTO_KEY = 'uye_tipi_iskonto';

    /**
     * Üye tipi 1-5 iskonto oranlarını döner (% olarak). Kullanıcı panelden
     * ayarladıysa onları, yoksa varsayılanları kullanır. Sıkı döngüde (her varyasyon)
     * çağrıldığı için process-içi statik cache'lenir; ayar değişince resetlenmeli.
     *
     * @return array<int,float> [1 => 35.0, 2 => 30.0, ...]
     */
    public static function uyeTipiIskontoOranlari(): array
    {
        if (self::$uyeTipiIskonto !== null) {
            return self::$uyeTipiIskonto;
        }

        $oranlar = self::UYE_TIPI_VARSAYILAN;
        try {
            $raw = SyncSetting::get(self::UYE_TIPI_ISKONTO_KEY, '');
            $saved = $raw ? json_decode($raw, true) : null;
            if (is_array($saved)) {
                foreach ($oranlar as $i => $varsayilan) {
                    if (isset($saved[$i]) && is_numeric($saved[$i])) {
                        // 0-100 arası sınırla (negatif/>100 anlamsız)
                        $oranlar[$i] = max(0.0, min(100.0, (float) $saved[$i]));
                    }
                }
            }
        } catch (\Throwable) {
            // Laravel app/DB yoksa (saf unit test) → varsayılanlar
        }

        return self::$uyeTipiIskonto = $oranlar;
    }

    /** Üye tipi iskonto cache'ini sıfırla (ayar güncellendiğinde / testlerde). */
    public static function resetUyeTipiIskontoCache(): void
    {
        self::$uyeTipiIskonto = null;
    }

    /**
     * Satış fiyatından üye tipi fiyatlarını hesapla.
     * Formül: SatisFiyati × (1 − iskonto_oranı/100). Oranlar panelden ayarlanabilir
     * (uyeTipiIskontoOranlari); varsayılan 35/30/40/25/20.
     */
    public static function calculateUyeTipiFiyatlari(float $satisFiyati): array
    {
        $o = self::uyeTipiIskontoOranlari();

        return [
            'UyeTipiFiyat1' => round($satisFiyati * (1 - $o[1] / 100), 2),
            'UyeTipiFiyat2' => round($satisFiyati * (1 - $o[2] / 100), 2),
            'UyeTipiFiyat3' => round($satisFiyati * (1 - $o[3] / 100), 2),
            'UyeTipiFiyat4' => round($satisFiyati * (1 - $o[4] / 100), 2),
            'UyeTipiFiyat5' => round($satisFiyati * (1 - $o[5] / 100), 2),
        ];
    }

    /**
     * UpdateUrunFiyat gerçek imzası: (UyeKodu, ArrayOfUpdateUrunFiyat request, UpdateUrunFiyatAyar ayar).
     * Barkoda göre eşleştir, fiyatları güncelle.
     * Üye tipi fiyatları SatisFiyati'ndan otomatik hesaplanır.
     */
    public function updatePrice(string $barkod, float $price, float $kdvOrani = 20.0, bool $kdvDahil = true): array
    {
        $uyeFiyatlar = self::calculateUyeTipiFiyatlari($price);

        $params = [
            'UyeKodu' => $this->client->getUyeKodu(),
            'request' => [[
                'Barkod' => $barkod,
                'Fiyatlar' => [
                    'SatisFiyati' => $price,
                    'IndirimliFiyat' => 0,
                    'KDVDahil' => $kdvDahil,
                    'KdvOrani' => (int) $kdvOrani,
                ] + $uyeFiyatlar,
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
                'UyeTipiFiyat1Guncelle' => true,
                'UyeTipiFiyat2Guncelle' => true,
                'UyeTipiFiyat3Guncelle' => true,
                'UyeTipiFiyat4Guncelle' => true,
                'UyeTipiFiyat5Guncelle' => true,
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
     * Toplu stok güncelleme — tek SOAP çağrısı (StokAdediGuncelle ArrayOfVaryasyon).
     *
     * @param  array  $varyasyonlar  [['ID' => bayiVarId, 'StokAdedi' => stock, 'Barkod' => barkod|null], ...]
     */
    public function updateStockBatch(array $varyasyonlar): void
    {
        if (empty($varyasyonlar)) {
            return;
        }
        $params = [
            'UyeKodu' => $this->client->getUyeKodu(),
            'urunler' => $varyasyonlar,
        ];
        $this->client->call('product', $this->method('update_stock'), $params);
    }

    /**
     * Toplu fiyat güncelleme — tek SOAP çağrısı (UpdateUrunFiyat ArrayOfUpdateUrunFiyat).
     *
     * @param  array  $items  [['Barkod' => barkod, 'SatisFiyati' => price, 'KdvOrani' => 20, 'KdvDahil' => true], ...]
     */
    public function updatePriceBatch(array $items): void
    {
        if (empty($items)) {
            return;
        }
        $request = array_map(function ($item) {
            $price = (float) $item['SatisFiyati'];
            $uyeFiyatlar = self::calculateUyeTipiFiyatlari($price);

            return [
                'Barkod' => $item['Barkod'],
                'Fiyatlar' => [
                    'SatisFiyati' => $price,
                    'IndirimliFiyat' => 0,
                    'KDVDahil' => (bool) ($item['KdvDahil'] ?? true),
                    'KdvOrani' => (int) ($item['KdvOrani'] ?? 20),
                ] + $uyeFiyatlar,
                'TedarikciKodu' => '',
                'TedarikciKodu2' => '',
                'UrunIds' => '',
                'UrunKartiIds' => '',
            ];
        }, $items);

        $params = [
            'UyeKodu' => $this->client->getUyeKodu(),
            'request' => $request,
            'ayar' => [
                'BarkodKodunaGoreGuncelle' => true,
                'IndirimliFiyatGuncelle' => false,
                'TedarikciKodu2GoreGuncelle' => false,
                'TedarikciKodunaGoreGuncelle' => false,
                'UrunIdGoreGuncelle' => false,
                'UrunKartiIdGoreGuncelle' => false,
                'UyeTipiFiyat1Guncelle' => true,
                'UyeTipiFiyat2Guncelle' => true,
                'UyeTipiFiyat3Guncelle' => true,
                'UyeTipiFiyat4Guncelle' => true,
                'UyeTipiFiyat5Guncelle' => true,
                'VaryasyonGuncelle' => true,
            ],
        ];
        $this->client->call('product', $this->method('update_price'), $params);
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
                // Eşleme anahtarı artık ana'nın GERÇEK TedarikciKodu'su (unique + değişmez).
                // Lokal mapping + ID match birincil yol; bu bayrak Ticimax tarafında ek
                // güvenlik kemeri: aynı tedarikçi kodu varsa yeni kart AÇMAZ, günceller.
                'TedarikciKodunaGoreGuncelle' => true,
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
                'TedarikciKodunaGoreGuncelle' => true,
            ],
        ];
    }

    protected function fullUpdateAyarlari(): array
    {
        return $this->fullCreateAyarlari();
    }

    /**
     * Manuel picker UI'dan gelen alan grubu set'ine gore ukAyar/vAyar uretir.
     * Tum 'Guncelle' flag'leri once false, sadece istenen gruptaki alanlar true yapilir.
     *
     * Desteklenen anahtarlar:
     *   'urun_adi', 'aciklama', 'on_yazi', 'kategori', 'marka', 'tedarikci',
     *   'satis_fiyati', 'indirimli_fiyat', 'stok_adedi', 'kdv_dahil', 'kdv_orani',
     *   'seo', 'uye_tipi_fiyat', 'resimler', 'aktif'
     *
     * @param  array  $fields  Etkin alan kimliklerinin listesi (orn. ['urun_adi','satis_fiyati'])
     * @return array ['ukAyar' => [...], 'vAyar' => [...]]
     */
    public function buildSelectiveAyarlari(array $fields): array
    {
        $f = array_flip($fields);
        $on = fn (string $k) => isset($f[$k]);

        $ukAyar = [
            // ===== ICERIK =====
            'UrunAdiGuncelle' => $on('urun_adi'),
            'SatisBirimiGuncelle' => $on('urun_adi'),
            'AciklamaGuncelle' => $on('aciklama'),
            'OnYaziGuncelle' => $on('on_yazi'),
            'AramaAnahtarKelimeGuncelle' => $on('seo'),

            // ===== KATEGORI =====
            'KategoriGuncelle' => $on('kategori'),
            'AnaKategoriGuncelle' => $on('kategori'),
            'OncekiKategoriEslestirmeleriniTemizle' => $on('kategori'),

            // ===== MARKA / TEDARIKCI =====
            'MarkaGuncelle' => $on('marka'),
            'TedarikciGuncelle' => $on('tedarikci'),
            'TedarikciKomisyonGuncelle' => $on('tedarikci'),

            // ===== SEO =====
            'SeoSayfaBaslikGuncelle' => $on('seo'),
            'SeoSayfaAciklamaGuncelle' => $on('seo'),
            'SeoAnahtarKelimeGuncelle' => $on('seo'),
            'SeoNoFollowGuncelle' => $on('seo'),
            'SeoNoIndexGuncelle' => $on('seo'),

            // ===== AKTIFLIK / GORUNUM (her biri ayri checkbox) =====
            'AktifGuncelle' => $on('aktif'),
            'ListedeGosterGuncelle' => $on('aktif'),
            'VitrinGuncelle' => $on('vitrin'),
            'YeniUrunGuncelle' => $on('yeni_urun'),
            'FirsatUrunuGuncelle' => $on('firsat_urunu'),

            // ===== RESIMLER =====
            'UrunResimGuncelle' => $on('resimler'),
            'IlgiliUrunResimGuncelle' => $on('resimler'),
            'ResimOlmayanlaraResimEkle' => $on('resimler'),
            'OncekiResimleriSil' => false,
            'Base64Resim' => false,
            'ResimleriIndirme' => false,

            // ===== ETIKETLER (SEO grubuna bagli) =====
            'EtiketGuncelle' => $on('seo'),

            // ===== UPSERT KAPALI (Ali konvansiyonu) =====
            'TedarikciKodunaGoreGuncelle' => false,
        ];

        // Uye tipi fiyatlarinin "hepsi" toplu secimini destekle (uye_tipi_fiyat) +
        // her birini ayri ayri (uye_tipi_fiyat_1 .. uye_tipi_fiyat_20)
        $uyeBayrak = function (int $i) use ($on) {
            return $on('uye_tipi_fiyat') || $on('uye_tipi_fiyat_'.$i);
        };
        $herhangiUye = false;
        for ($i = 1; $i <= 20; $i++) {
            if ($uyeBayrak($i)) {
                $herhangiUye = true;
                break;
            }
        }

        $vAyar = [
            // ===== STOK =====
            'StokAdediGuncelle' => $on('stok_adedi'),
            'EksiStokAdediGuncelle' => $on('eksi_stok_adedi'),

            // ===== FIYAT =====
            'SatisFiyatiGuncelle' => $on('satis_fiyati'),
            'IndirimliFiyatiGuncelle' => $on('indirimli_fiyat'),
            'AlisFiyatiGuncelle' => $on('satis_fiyati'),
            'PiyasaFiyatiGuncelle' => $on('satis_fiyati'),

            // ===== KDV =====
            'KdvDahilGuncelle' => $on('kdv_dahil'),
            'KdvOraniGuncelle' => $on('kdv_orani'),

            // ===== PARA BIRIMI =====
            'ParaBirimiGuncelle' => $on('satis_fiyati'),

            // ===== UYE TIPI FIYATLARI (1..20) =====
            // Tek bayrak: herhangi biri secili ise true (Ticimax bu bayragi gormezse
            // bireysel UyeTipiFiyatNGuncelle'leri de yok sayabilir — emniyet kemeri)
            'FiyatTipleriGuncelle' => $herhangiUye,
            'UyeTipiFiyat1Guncelle' => $uyeBayrak(1),
            'UyeTipiFiyat2Guncelle' => $uyeBayrak(2),
            'UyeTipiFiyat3Guncelle' => $uyeBayrak(3),
            'UyeTipiFiyat4Guncelle' => $uyeBayrak(4),
            'UyeTipiFiyat5Guncelle' => $uyeBayrak(5),
            'UyeTipiFiyat6Guncelle' => $uyeBayrak(6),
            'UyeTipiFiyat7Guncelle' => $uyeBayrak(7),
            'UyeTipiFiyat8Guncelle' => $uyeBayrak(8),
            'UyeTipiFiyat9Guncelle' => $uyeBayrak(9),
            'UyeTipiFiyat10Guncelle' => $uyeBayrak(10),
            'UyeTipiFiyat11Guncelle' => $uyeBayrak(11),
            'UyeTipiFiyat12Guncelle' => $uyeBayrak(12),
            'UyeTipiFiyat13Guncelle' => $uyeBayrak(13),
            'UyeTipiFiyat14Guncelle' => $uyeBayrak(14),
            'UyeTipiFiyat15Guncelle' => $uyeBayrak(15),
            'UyeTipiFiyat16Guncelle' => $uyeBayrak(16),
            'UyeTipiFiyat17Guncelle' => $uyeBayrak(17),
            'UyeTipiFiyat18Guncelle' => $uyeBayrak(18),
            'UyeTipiFiyat19Guncelle' => $uyeBayrak(19),
            'UyeTipiFiyat20Guncelle' => $uyeBayrak(20),

            // ===== AKTIFLIK =====
            'AktifGuncelle' => $on('aktif'),
            'UrunKartiAktifGuncelle' => $on('aktif'),

            // ===== RESIMLER (varyasyon seviyesinde) =====
            'UrunResimGuncelle' => $on('resimler'),

            // ===== BARKOD / STOK KODU (kullanici acabilir) =====
            'BarkodGuncelle' => $on('barkod'),
            'StokKoduGuncelle' => $on('stok_kodu'),

            // ===== UPSERT KAPALI =====
            'OncekiResimleriSil' => false,
            'TedarikciKodunaGoreGuncelle' => false,
        ];

        return ['ukAyar' => $ukAyar, 'vAyar' => $vAyar];
    }

    /**
     * Picker UI'dan secilen alan grubu ile mevcut bir urunu UPDATE et.
     * Bayide mevcut UrunKartiID + (varsa) bayi VaryasyonID payload'a yazilir,
     * sadece secili alan grubuna karsilik gelen Guncelle flag'leri true gonderilir.
     *
     * @param  array  $urunKarti  Mapper'in urettigi tam payload (ana'dan)
     * @param  int  $bayiProductId  Bayi'deki mevcut UrunKarti.ID
     * @param  array<string,int>  $bayiVariantIdByStokKodu  StokKodu → bayi Varyasyon.ID
     * @param  array  $selectedFields  Acik alan listesi (orn. ['urun_adi','satis_fiyati'])
     */
    public function updateProductSelective(array $urunKarti, int $bayiProductId, array $bayiVariantIdByStokKodu, array $selectedFields, ?array $bayiKart = null): array
    {
        $urunKarti['ID'] = $bayiProductId;

        // OVERRIDE KORUMASI: kullanici isaretlemediginin alanlari payload'da
        // bayi'nin ORIJINAL degeriyle restore et — boylece elden eklenmis (manuel)
        // urunlerde bizim audit kodumuzun veya cross-store ID'lerin sessizce
        // ezilmesi onlenir. ($bayiKart cagiri tarafindan getProductByStokKodu/ID
        // ile gecirilir; yoksa bu koruma calismaz — geri donus yine eskisi gibi.)
        if ($bayiKart !== null) {
            $urunKarti = $this->preserveBayiFields($urunKarti, $bayiKart, $selectedFields);
        }

        // Varyasyonlara bayi ID'lerini yaz (lokal-oncelikli eslesme)
        if (! empty($urunKarti['Varyasyonlar']) && is_array($urunKarti['Varyasyonlar'])) {
            // Bayi varyasyonlarini StokKodu → varyasyon obj olarak hazirla (overlay icin)
            $bayiVarByStok = [];
            if ($bayiKart !== null) {
                $bv = $bayiKart['Varyasyonlar']['Varyasyon'] ?? $bayiKart['Varyasyonlar'] ?? [];
                if (isset($bv['Barkod'])) {
                    $bv = [$bv];
                }
                if (is_array($bv)) {
                    foreach ($bv as $vv) {
                        $sk = (string) ($vv['StokKodu'] ?? '');
                        if ($sk !== '') {
                            $bayiVarByStok[$sk] = $vv;
                        }
                    }
                }
            }
            foreach ($urunKarti['Varyasyonlar'] as &$v) {
                $sk = (string) ($v['StokKodu'] ?? '');
                if ($sk !== '' && isset($bayiVariantIdByStokKodu[$sk])) {
                    $v['ID'] = (int) $bayiVariantIdByStokKodu[$sk];
                    $v['UrunKartiID'] = $bayiProductId;
                }
                // Varyasyon override koruması
                if (isset($bayiVarByStok[$sk])) {
                    $v = $this->preserveBayiVariantFields($v, $bayiVarByStok[$sk], $selectedFields);
                }
            }
            unset($v);
        }

        // RESİMLER: 'resimler' seçili DEĞİLSE payload'dan tüm resimleri tamamen çıkar.
        // Mevcut ürün güncellemelerinde resim hiç gönderilmez → bayi'nin mevcut resimleri
        // olduğu gibi kalır (UrunResimGuncelle/ResimOlmayanlaraResimEkle zaten false).
        // Bu, hızlı yolda da (lokal eşleşme, $bayiKart=null) ana resim URL'lerinin
        // sızmasını kesin olarak engeller. Yeni ürün OLUŞTURMA yolu (createProduct)
        // bu metottan geçmez → yeni ürünlerde resimler şimdiki gibi aktarılır.
        if (! in_array('resimler', $selectedFields, true)) {
            $urunKarti['Resimler'] = [];
            if (! empty($urunKarti['Varyasyonlar']) && is_array($urunKarti['Varyasyonlar'])) {
                foreach ($urunKarti['Varyasyonlar'] as &$vr) {
                    $vr['Resimler'] = [];
                }
                unset($vr);
            }
        }

        $ayarlar = $this->buildSelectiveAyarlari($selectedFields);

        return $this->saveBatch([$urunKarti], $ayarlar)[0] ?? [];
    }

    /**
     * UrunKarti seviyesinde override koruması: $selectedFields'da olmayan her alan
     * icin payload'a bayi'nin orijinal degerini yaz.
     */
    protected function preserveBayiFields(array $payload, array $bayiKart, array $selectedFields): array
    {
        $sel = array_flip($selectedFields);
        $on = fn (string $k) => isset($sel[$k]);

        // urun_adi
        if (! $on('urun_adi')) {
            $payload['UrunAdi'] = $bayiKart['UrunAdi'] ?? ($payload['UrunAdi'] ?? '');
            $payload['SatisBirimi'] = $bayiKart['SatisBirimi'] ?? ($payload['SatisBirimi'] ?? '');
        }
        // aciklama / on_yazi
        if (! $on('aciklama')) {
            $payload['Aciklama'] = $bayiKart['Aciklama'] ?? ($payload['Aciklama'] ?? '');
        }
        if (! $on('on_yazi')) {
            $payload['OnYazi'] = $bayiKart['OnYazi'] ?? ($payload['OnYazi'] ?? '');
        }
        // SEO
        if (! $on('seo')) {
            foreach (['SeoSayfaBaslik', 'SeoSayfaAciklama', 'SeoAnahtarKelime', 'AramaAnahtarKelime', 'Etiketler', 'SeoNoFollow', 'SeoNoIndex'] as $f) {
                if (array_key_exists($f, $bayiKart)) {
                    $payload[$f] = $bayiKart[$f];
                }
            }
        }
        // Kategori
        if (! $on('kategori')) {
            $payload['AnaKategori'] = $bayiKart['AnaKategori'] ?? ($payload['AnaKategori'] ?? '');
            $payload['AnaKategoriID'] = $bayiKart['AnaKategoriID'] ?? 0;
            $payload['Kategoriler'] = $bayiKart['Kategoriler'] ?? ($payload['Kategoriler'] ?? []);
        }
        // Marka
        if (! $on('marka')) {
            $payload['MarkaID'] = $bayiKart['MarkaID'] ?? 0;
            $payload['Marka'] = $bayiKart['Marka'] ?? '';
        }
        // Tedarikci (KRITIK: TedarikciKodu manuel girilmis audit kodu olabilir)
        if (! $on('tedarikci')) {
            $payload['TedarikciID'] = $bayiKart['TedarikciID'] ?? 0;
            $payload['TedarikciKodu'] = $bayiKart['TedarikciKodu'] ?? '';
            $payload['TedarikciKodu2'] = $bayiKart['TedarikciKodu2'] ?? '';
            $payload['TedarikciKomisyonOrani'] = $bayiKart['TedarikciKomisyonOrani'] ?? 0;
        }
        // Resimler
        if (! $on('resimler')) {
            // Bayi'nin string array'i — wrapper ile geri yaz
            $bayiResim = $bayiKart['Resimler']['string'] ?? $bayiKart['Resimler'] ?? null;
            if ($bayiResim !== null) {
                if (is_string($bayiResim)) {
                    $bayiResim = [$bayiResim];
                }
                if (is_array($bayiResim) && ! empty($bayiResim)) {
                    $payload['Resimler'] = ['string' => array_values($bayiResim)];
                }
            }
        }
        // Aktif & gosterim flag'leri (her biri ayri)
        if (! $on('aktif')) {
            $payload['Aktif'] = $bayiKart['Aktif'] ?? true;
            $payload['ListedeGoster'] = $bayiKart['ListedeGoster'] ?? true;
        }
        if (! $on('vitrin')) {
            $payload['Vitrin'] = $bayiKart['Vitrin'] ?? false;
        }
        if (! $on('yeni_urun')) {
            $payload['YeniUrun'] = $bayiKart['YeniUrun'] ?? false;
        }
        if (! $on('firsat_urunu')) {
            $payload['FirsatUrunu'] = $bayiKart['FirsatUrunu'] ?? false;
        }

        // UrunSayfaAdresi — biz hep boş gönderiyoruz (Ticimax üretsin); bayide
        // mevcut SEO URL'i koru ki re-yazılınca link değişmesin
        $payload['UrunSayfaAdresi'] = $bayiKart['UrunSayfaAdresi'] ?? '';

        return $payload;
    }

    /**
     * Varyasyon seviyesinde override koruması.
     */
    protected function preserveBayiVariantFields(array $v, array $bayiVar, array $selectedFields): array
    {
        $sel = array_flip($selectedFields);
        $on = fn (string $k) => isset($sel[$k]);

        if (! $on('stok_adedi')) {
            $v['StokAdedi'] = $bayiVar['StokAdedi'] ?? 0;
        }
        if (! $on('eksi_stok_adedi')) {
            $v['EksiStokAdedi'] = $bayiVar['EksiStokAdedi'] ?? 0;
        }
        if (! $on('satis_fiyati')) {
            $v['SatisFiyati'] = $bayiVar['SatisFiyati'] ?? 0;
            $v['AlisFiyati'] = $bayiVar['AlisFiyati'] ?? 0;
            $v['PiyasaFiyati'] = $bayiVar['PiyasaFiyati'] ?? 0;
        }
        if (! $on('indirimli_fiyat')) {
            $v['IndirimliFiyati'] = $bayiVar['IndirimliFiyati'] ?? 0;
        }
        if (! $on('kdv_dahil')) {
            $v['KdvDahil'] = $bayiVar['KdvDahil'] ?? true;
        }
        if (! $on('kdv_orani')) {
            $v['KdvOrani'] = $bayiVar['KdvOrani'] ?? 20;
        }
        if (! $on('barkod')) {
            $v['Barkod'] = $bayiVar['Barkod'] ?? ($v['Barkod'] ?? '');
        }
        if (! $on('stok_kodu')) {
            $v['StokKodu'] = $bayiVar['StokKodu'] ?? ($v['StokKodu'] ?? '');
        }
        // Tedarikci (varyasyon seviyesi)
        if (! $on('tedarikci')) {
            $v['TedarikciKodu'] = $bayiVar['TedarikciKodu'] ?? '';
            $v['TedarikciKodu2'] = $bayiVar['TedarikciKodu2'] ?? '';
        }
        // Uye tipi fiyatlari 1..20
        // UyeTipiFiyat 1-5 seciliyse: SatisFiyati'ndan formülle hesapla (ana'dan gelen deger degil)
        $uyeSecili = $on('uye_tipi_fiyat'); // toplu toggle
        $satisFiyati = (float) ($v['SatisFiyati'] ?? 0);
        $formulFiyatlar = ($uyeSecili || $on('uye_tipi_fiyat_1') || $on('uye_tipi_fiyat_2')
            || $on('uye_tipi_fiyat_3') || $on('uye_tipi_fiyat_4') || $on('uye_tipi_fiyat_5'))
            ? self::calculateUyeTipiFiyatlari($satisFiyati)
            : [];

        for ($i = 1; $i <= 20; $i++) {
            $key = 'UyeTipiFiyat'.$i;
            if ($uyeSecili || $on('uye_tipi_fiyat_'.$i)) {
                // Secili: 1-5 icin formul, 6-20 icin ana'dan gelen deger
                if (isset($formulFiyatlar[$key])) {
                    $v[$key] = $formulFiyatlar[$key];
                }
                // 6-20 icin ana'dan gelen deger zaten payload'da
            } else {
                // Secili degil: bayi'nin orijinalini koru
                if (isset($bayiVar[$key])) {
                    $v[$key] = $bayiVar[$key];
                }
            }
        }
        // Aktif
        if (! $on('aktif')) {
            $v['Aktif'] = $bayiVar['Aktif'] ?? true;
        }

        return $v;
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
        $resultKey = $method.'Result';
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
