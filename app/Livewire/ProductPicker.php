<?php

namespace App\Livewire;

use App\Models\ProductMapping;
use App\Models\SyncJob;
use App\Models\SyncLog;
use App\Services\Ticimax\ProductMapper;
use App\Services\Ticimax\ProductService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Title('Manuel Ürün Aktarımı')]
#[Layout('layouts.app')]
class ProductPicker extends Component
{
    /* ---------------------------------------------------------------------
     |  Arama / listeleme durumu
     * --------------------------------------------------------------------- */
    #[Url(as: 'q')]
    public string $query = '';

    /** Listeleme sonucu — tabloya basılan düz satırlar (her varyasyon bir satır) */
    public array $products = [];

    /** Seçili VaryasyonID listesi (string olarak Livewire ile gönderim) */
    public array $selected = [];
    public bool $selectAll = false;

    /** Tüm listede sayfalama (Ticimax: 100 ürün/sayfa) */
    public int $page = 1;
    public int $perPage = 100;
    public bool $hasMore = false;

    /** UI durumu */
    public bool $hasSearched = false;
    public int $resultCount = 0;
    public ?string $error = null;
    public ?string $status = null;

    /* ---------------------------------------------------------------------
     |  Aktarım parametreleri (alan checkbox'ları)
     * --------------------------------------------------------------------- */
    public array $fields = [
        // ----- Icerik -----
        'urun_adi'         => true,
        'aciklama'         => true,
        'on_yazi'          => true,
        // ----- Kategori / Marka / Tedarikci -----
        'kategori'         => true,
        'marka'            => true,
        'tedarikci'        => true,
        // ----- Fiyat / Stok -----
        'satis_fiyati'     => true,
        'indirimli_fiyat'  => true,
        'stok_adedi'       => true,
        'eksi_stok_adedi'  => false,   // yeni — varsayilan kapali (riskli)
        'kdv_dahil'        => true,
        'kdv_orani'        => true,
        // ----- Identifiers (yeni — riskli, varsayilan kapali) -----
        'stok_kodu'        => false,
        'barkod'           => false,
        // ----- SEO / Gorsel -----
        'seo'              => true,
        'resimler'         => true,
        // ----- Aktiflik / Gorunum (her biri ayri) -----
        'aktif'            => true,
        'vitrin'           => false,   // yeni — manuel toggle
        'yeni_urun'        => false,   // yeni
        'firsat_urunu'     => false,   // yeni
        // ----- Uye tipi fiyatlari (toplu) -----
        'uye_tipi_fiyat'   => false,   // toplu — varsayilan kapali; her biri ayri seçilebilir
    ];

    /** Uye tipi fiyatlari 1..20 ayri ayri (key: uye_tipi_fiyat_N) — Livewire dot notation icin) */
    public array $uyeTipi = [
        1=>false, 2=>false, 3=>false, 4=>false, 5=>false,
        6=>false, 7=>false, 8=>false, 9=>false, 10=>false,
        11=>false,12=>false,13=>false,14=>false,15=>false,
        16=>false,17=>false,18=>false,19=>false,20=>false,
    ];

    /** Aktarım sonuçları */
    public array $results = [];

    /* ---------------------------------------------------------------------
     |  Aksiyonlar
     * --------------------------------------------------------------------- */

    public function listele(): void
    {
        $this->error = null;
        $this->status = null;
        $this->products = [];
        $this->selected = [];
        $this->selectAll = false;
        $this->resultCount = 0;
        $this->hasMore = false;
        $this->hasSearched = true;
        // Yeni listede sayfa 1'den başla
        $this->page = 1;
        $this->loadPage();
    }

    public function sonrakiSayfa(): void
    {
        if ($this->hasMore) {
            $this->page++;
            $this->loadPage();
        }
    }

    public function oncekiSayfa(): void
    {
        if ($this->page > 1) {
            $this->page--;
            $this->loadPage();
        }
    }

    /**
     * Mevcut sayfayı ana mağazadan çek.
     * - Stok kodu BOŞ ise: getNewProducts ile tüm ürünleri sayfa sayfa listele
     * - Doluysa: searchProductsByStokKodu (tek/çoklu/LIKE)
     */
    protected function loadPage(): void
    {
        $q = trim($this->query);
        try {
            $ana = ProductService::for('ana');
            if ($q === '') {
                $batch = $ana->getNewProducts(null, $this->page, $this->perPage, 'DESC');
                // Sonraki sayfa MUMKUN — Ticimax bir sayfada her zaman tam perPage
                // dondurmeyebilir (filtre, gizli urun, vs.). Kullanıcıya ileri/geri
                // serbest dolasim sun; bos sayfa gelirse mesajla uyariniz.
                $this->hasMore = count($batch) > 0;
            } else {
                // Arama tek seferde tüm sonuçları döner (sayfalama gereksiz)
                $batch = $ana->searchProductsByStokKodu($q);
                $this->hasMore = false;
                $this->page = 1;
            }
            $this->products = $this->flatten($batch);
            $this->resultCount = count($this->products);
            if ($this->resultCount === 0) {
                if ($q === '') {
                    $this->error = $this->page > 1
                        ? "Sayfa {$this->page}'de ürün yok — son sayfayı geçtin. Geri dön."
                        : 'Ana mağazada ürün yok.';
                } else {
                    $this->error = 'Aramaya uyan ürün bulunamadı.';
                }
            }
        } catch (\Throwable $e) {
            $this->error = 'Listeleme hatası: ' . $e->getMessage();
        }
        // Seçimler sayfa değişimini izlemiyor (basit MVP — her sayfa kendi seçimi)
        $this->selected = [];
        $this->selectAll = false;
    }

    public function updatedSelectAll(bool $value): void
    {
        $this->selected = $value
            ? array_map(fn($r) => (string) $r['variant_id'], $this->products)
            : [];
    }

    public function tumunuSec(): void
    {
        foreach (array_keys($this->fields) as $k) $this->fields[$k] = true;
        foreach (array_keys($this->uyeTipi) as $k) $this->uyeTipi[$k] = true;
    }
    public function hicbirini(): void
    {
        foreach (array_keys($this->fields) as $k) $this->fields[$k] = false;
        foreach (array_keys($this->uyeTipi) as $k) $this->uyeTipi[$k] = false;
    }

    /**
     * fields + uyeTipi'yi tek bir 'selectedFields' listesine birlestir.
     * buildSelectiveAyarlari uye_tipi_fiyat_N anahtarlarini bekler.
     */
    protected function collectSelectedFields(): array
    {
        $out = array_keys(array_filter($this->fields));
        foreach ($this->uyeTipi as $i => $on) {
            if ($on) $out[] = 'uye_tipi_fiyat_' . $i;
        }
        return $out;
    }

    /**
     * Sadece yeni ürünleri aktar (seçim YOK).
     * Listedeki tüm ürünleri bayide kontrol eder, bayide olmayanları yeni ürün
     * olarak ekler. Aktarılan her ürün için SyncLog kaydı oluşturur — log ekranında
     * stok kodu + barkod ile görünür.
     */
    public function yeniUrunleriAktar(): void
    {
        $this->results = [];
        $this->status = null;
        $this->error = null;

        if (empty($this->products)) {
            $this->error = 'Listede ürün yok — önce "Listele" tıkla.';
            return;
        }

        // Aynı UrunKartı birden fazla varyasyon ile gelmiş olabilir; UrunKartiID
        // bazında tek seferlik aktarım yapacağız. Her UrunKartı için primary StokKodu
        // ile bayide var/yok kontrolü.
        $byUrunKarti = [];
        foreach ($this->products as $row) {
            $uid = (int) $row['urun_karti_id'];
            if (! isset($byUrunKarti[$uid])) {
                $byUrunKarti[$uid] = $row; // ilk varyasyon temsilci
            }
        }

        $ana = ProductService::for('ana');
        $bayi = ProductService::for('bayi');
        $mapper = $this->buildMapper($ana, $bayi);

        // SyncJob kaydı — log ekranında "manuel yeni ürün aktarımı" işi olarak gözüksün
        $job = SyncJob::create([
            'type' => 'product_create',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $yeni = 0; $atlandi = 0; $hata = 0;
        foreach ($byUrunKarti as $row) {
            $stokKodu = $row['stok_kodu'] ?? '';
            $barkod   = $row['barkod'] ?? '';
            $urunAdi  = $row['urun_adi'] ?? '';
            $raw      = $row['_raw'] ?? null;

            if (! is_array($raw) || $stokKodu === '') {
                $this->results[] = ['stok_kodu' => $stokKodu, 'urun_adi' => $urunAdi, 'durum' => 'atlandi', 'mesaj' => 'StokKodu yok'];
                $atlandi++;
                continue;
            }

            $job->increment('total');

            try {
                // Bayide var mı?
                $bayiMevcut = $bayi->getProductByStokKodu($stokKodu);
                if ($bayiMevcut && (int) ($bayiMevcut['ID'] ?? 0) > 0) {
                    $this->results[] = ['stok_kodu' => $stokKodu, 'urun_adi' => $urunAdi, 'durum' => 'atlandi', 'mesaj' => 'Zaten bayide var (ID=' . $bayiMevcut['ID'] . ')'];
                    $atlandi++;
                    continue;
                }

                // YENİ ürün — oluştur
                $payload = $mapper->anaToBayiCreatePayload($raw);
                $created = $bayi->createProduct($payload);
                $bayiId = (int) ($created['ID'] ?? 0);

                $msg = "Yeni ürün aktarıldı — stok kodu: {$stokKodu} | barkod: {$barkod} | bayi ID: {$bayiId}";
                $this->results[] = ['stok_kodu' => $stokKodu, 'urun_adi' => $urunAdi, 'durum' => 'olusturuldu', 'mesaj' => $msg];

                // Log ekranina yaz
                $job->increment('success_count');
                SyncLog::create([
                    'job_id' => $job->id,
                    'action' => 'create_product',
                    'direction' => 'ana_to_bayi',
                    'status' => 'success',
                    'barcode' => $barkod ?: null,
                    'stok_kodu' => $stokKodu,
                    'urun_adi' => $urunAdi,
                    'ana_id' => $row['urun_karti_id'] ?? null,
                    'message' => $msg,
                ]);

                $this->upsertMapping($row, $bayiId, []);
                $yeni++;
            } catch (\Throwable $e) {
                $errMsg = substr($e->getMessage(), 0, 250);
                $this->results[] = ['stok_kodu' => $stokKodu, 'urun_adi' => $urunAdi, 'durum' => 'hata', 'mesaj' => $errMsg];
                $job->increment('error_count');
                SyncLog::create([
                    'job_id' => $job->id,
                    'action' => 'create_product',
                    'direction' => 'ana_to_bayi',
                    'status' => 'error',
                    'barcode' => $barkod ?: null,
                    'stok_kodu' => $stokKodu,
                    'urun_adi' => $urunAdi,
                    'ana_id' => $row['urun_karti_id'] ?? null,
                    'message' => $errMsg,
                ]);
                $hata++;
            }
        }

        $job->update(['status' => 'completed', 'finished_at' => now()]);

        $this->status = "Yeni ürün aktarımı tamamlandı: {$yeni} eklendi"
            . ($atlandi ? ", {$atlandi} atlandı (zaten bayide)" : '')
            . ($hata ? ", {$hata} hata" : '')
            . ". Detay: Loglar sayfasında görüntülenebilir.";
    }

    /**
     * Hızlı yol: seçili ürünlerin sadece STOK ve FİYAT bilgilerini bayide günceller.
     * Bayide olmayan ürünler atlanır (oluşturma yapmaz — sadece update).
     */
    public function stokFiyatGuncelle(): void
    {
        $this->results = [];
        $this->status = null;

        if (empty($this->selected)) {
            $this->error = 'Güncellemek için ürün seçin.';
            return;
        }
        $this->error = null;

        // Sadece stok + satış + indirimli fiyat alanları
        $fields = ['stok_adedi', 'satis_fiyati', 'indirimli_fiyat'];

        $rows = array_values(array_filter($this->products, fn($r) => in_array((string) $r['variant_id'], $this->selected, true)));

        $ana = ProductService::for('ana');
        $bayi = ProductService::for('bayi');
        $mapper = $this->buildMapper($ana, $bayi);

        $ok = 0; $atlandi = 0; $fail = 0;
        foreach ($rows as $row) {
            $stokKodu = $row['stok_kodu'] ?? '';
            $urunAdi  = $row['urun_adi'] ?? '';
            $raw      = $row['_raw'] ?? null;

            if (! is_array($raw) || $stokKodu === '') {
                $this->results[] = ['stok_kodu' => $stokKodu, 'urun_adi' => $urunAdi, 'durum' => 'atlandi', 'mesaj' => 'Ham payload yok'];
                $atlandi++;
                continue;
            }

            try {
                $bayiMevcut = $bayi->getProductByStokKodu($stokKodu);
                if (! $bayiMevcut || (int) ($bayiMevcut['ID'] ?? 0) === 0) {
                    $this->results[] = ['stok_kodu' => $stokKodu, 'urun_adi' => $urunAdi, 'durum' => 'atlandi', 'mesaj' => 'Bayide bulunamadı (önce aktar)'];
                    $atlandi++;
                    continue;
                }

                $payload = $mapper->anaToBayiCreatePayload($raw);
                $bayiId = (int) $bayiMevcut['ID'];
                $varMap = $this->mapBayiVariantIds($bayiMevcut);
                // bayiMevcut'u 5. parametre olarak gec — override koruması calissin
                $bayi->updateProductSelective($payload, $bayiId, $varMap, $fields, $bayiMevcut);

                $stok = $row['stok_adedi'] ?? 0;
                $fiyat = number_format((float) ($row['satis_fiyati'] ?? 0), 2, ',', '.');
                $this->results[] = [
                    'stok_kodu' => $stokKodu, 'urun_adi' => $urunAdi,
                    'durum' => 'guncellendi',
                    'mesaj' => "bayi ID={$bayiId} | stok={$stok} fiyat={$fiyat}",
                ];
                $this->upsertMapping($row, $bayiId, $varMap);
                $ok++;
            } catch (\Throwable $e) {
                $this->results[] = ['stok_kodu' => $stokKodu, 'urun_adi' => $urunAdi, 'durum' => 'hata', 'mesaj' => substr($e->getMessage(), 0, 200)];
                $fail++;
            }
        }

        $this->status = "Stok/Fiyat güncelleme tamamlandı: {$ok} başarılı"
            . ($atlandi ? ", {$atlandi} atlandı (bayide yok)" : '')
            . ($fail ? ", {$fail} hata" : '') . '.';
        $this->selected = [];
        $this->selectAll = false;
    }

    /**
     * Seçili ürünleri belirlenen parametrelere göre bayi'ye aktar.
     */
    public function aktar(): void
    {
        $this->results = [];
        $this->status = null;

        if (empty($this->selected)) {
            $this->error = 'Aktarmak için ürün seçin.';
            return;
        }
        $selectedFields = $this->collectSelectedFields();
        if (empty($selectedFields)) {
            $this->error = 'En az bir parametre seçin.';
            return;
        }
        $this->error = null;

        // Seçili satırları products içinden bul
        $rows = array_values(array_filter($this->products, fn($r) => in_array((string) $r['variant_id'], $this->selected, true)));
        if (empty($rows)) {
            $this->error = 'Seçili ürünler listede bulunamadı.';
            return;
        }

        $ana = ProductService::for('ana');
        $bayi = ProductService::for('bayi');
        $mapper = $this->buildMapper($ana, $bayi);

        $ok = 0; $fail = 0;
        foreach ($rows as $row) {
            $stokKodu = $row['stok_kodu'] ?? '';
            $urunAdi  = $row['urun_adi'] ?? '';
            $raw      = $row['_raw'] ?? null;

            if (! is_array($raw) || $stokKodu === '') {
                $this->results[] = ['stok_kodu' => $stokKodu, 'urun_adi' => $urunAdi, 'durum' => 'atlandi', 'mesaj' => 'Ham payload yok'];
                $fail++;
                continue;
            }

            try {
                $bayiMevcut = $bayi->getProductByStokKodu($stokKodu);
                $payload = $mapper->anaToBayiCreatePayload($raw);

                if ($bayiMevcut && (int) ($bayiMevcut['ID'] ?? 0) > 0) {
                    $bayiId = (int) $bayiMevcut['ID'];
                    $varMap = $this->mapBayiVariantIds($bayiMevcut);
                    // bayiMevcut'u 5. parametre olarak gec — override koruması calissin
                    $bayi->updateProductSelective($payload, $bayiId, $varMap, $selectedFields, $bayiMevcut);
                    $this->results[] = ['stok_kodu' => $stokKodu, 'urun_adi' => $urunAdi, 'durum' => 'guncellendi', 'mesaj' => "bayi ID={$bayiId}"];
                    $this->upsertMapping($row, $bayiId, $varMap);
                    $ok++;
                } else {
                    $created = $bayi->createProduct($payload);
                    $bayiId = (int) ($created['ID'] ?? 0);
                    $this->results[] = ['stok_kodu' => $stokKodu, 'urun_adi' => $urunAdi, 'durum' => 'olusturuldu', 'mesaj' => "bayi ID={$bayiId}"];
                    $this->upsertMapping($row, $bayiId, []);
                    $ok++;
                }
            } catch (\Throwable $e) {
                $this->results[] = ['stok_kodu' => $stokKodu, 'urun_adi' => $urunAdi, 'durum' => 'hata', 'mesaj' => substr($e->getMessage(), 0, 200)];
                $fail++;
            }
        }
        $this->status = "Aktarım tamamlandı: {$ok} başarılı, {$fail} hata.";
        $this->selected = [];
        $this->selectAll = false;
    }

    /* ---------------------------------------------------------------------
     |  Yardımcılar
     * --------------------------------------------------------------------- */

    protected function flatten(array $urunKartlari): array
    {
        $rows = [];
        foreach ($urunKartlari as $uk) {
            $variants = $uk['Varyasyonlar']['Varyasyon'] ?? $uk['Varyasyonlar'] ?? [];
            if (isset($variants['Barkod'])) $variants = [$variants];
            if (! is_array($variants) || empty($variants)) continue;
            foreach ($variants as $v) {
                $rows[] = [
                    'urun_karti_id' => (int) ($uk['ID'] ?? 0),
                    'variant_id'    => (int) ($v['ID'] ?? 0),
                    'urun_adi'      => (string) ($uk['UrunAdi'] ?? ''),
                    'stok_kodu'     => (string) ($v['StokKodu'] ?? ''),
                    'barkod'        => (string) ($v['Barkod'] ?? ''),
                    'stok_adedi'    => (int) ($v['StokAdedi'] ?? 0),
                    'satis_fiyati'  => (float) ($v['SatisFiyati'] ?? 0),
                    'indirimli_fiyat' => (float) ($v['IndirimliFiyati'] ?? 0),
                    'aktif'         => (bool) (($v['Aktif'] ?? true) && ($uk['Aktif'] ?? true)),
                    '_raw'          => $uk,
                ];
            }
        }
        return $rows;
    }

    protected function mapBayiVariantIds(array $bayiKart): array
    {
        $vars = $bayiKart['Varyasyonlar']['Varyasyon'] ?? $bayiKart['Varyasyonlar'] ?? [];
        if (isset($vars['Barkod'])) $vars = [$vars];
        $out = [];
        if (is_array($vars)) {
            foreach ($vars as $v) {
                $sk = (string) ($v['StokKodu'] ?? '');
                $id = (int) ($v['ID'] ?? 0);
                if ($sk !== '' && $id > 0) $out[$sk] = $id;
            }
        }
        return $out;
    }

    protected function upsertMapping(array $row, int $bayiProductId, array $bayiVarMap): void
    {
        $stokKodu = $row['stok_kodu'] ?? '';
        if ($stokKodu === '') return;
        ProductMapping::updateOrCreate(
            ['stok_kodu' => $stokKodu],
            [
                'barcode' => $row['barkod'] ?? null,
                'ana_variant_id' => $row['variant_id'] ?? null,
                'bayi_product_id' => $bayiProductId ?: null,
                'bayi_variant_id' => $bayiVarMap[$stokKodu] ?? null,
                'last_price' => $row['satis_fiyati'] ?? null,
                'last_stock' => $row['stok_adedi'] ?? null,
                'status' => 'synced',
                'last_error' => null,
                'last_synced_at' => now(),
            ]
        );
    }

    protected function buildMapper(ProductService $ana, ProductService $bayi): ProductMapper
    {
        $mapper = new ProductMapper();
        $defaultBrandId = $bayi->getDefaultBrandId();
        $defaultSupplierId = $bayi->getDefaultSupplierId();
        $defaultCategoryId = $bayi->getDefaultCategoryId();
        $mapper->setDefaultCategoryId($defaultCategoryId);

        $mapper->setBrandResolver(function (string $name) use ($bayi, $defaultBrandId) {
            $id = $bayi->findOrCreateBrandId($name);
            return $id > 0 ? $id : $defaultBrandId;
        });
        $anaSupplierIdToName = array_flip($ana->getSupplierMap());
        $mapper->setSupplierResolver(function (int $anaId) use ($anaSupplierIdToName, $bayi, $defaultSupplierId) {
            $name = $anaSupplierIdToName[$anaId] ?? '';
            $id = $name ? $bayi->findOrCreateSupplierId($name) : 0;
            return $id > 0 ? $id : $defaultSupplierId;
        });

        $anaTree = $ana->getCategoryTree();
        $bayi->getCategoryTree();
        $mapper->setCategoryIdResolver(function (int $anaCatId) use ($bayi, $anaTree, $defaultCategoryId) {
            $bid = $bayi->mirrorCategoryFromAna($anaCatId, $anaTree);
            return $bid > 0 ? $bid : $defaultCategoryId;
        });

        return $mapper;
    }

    public function render()
    {
        return view('livewire.product-picker');
    }
}
