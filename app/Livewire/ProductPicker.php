<?php

namespace App\Livewire;

use App\Models\ProductMapping;
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
        'urun_adi'        => true,
        'aciklama'        => true,
        'on_yazi'         => true,
        'kategori'        => true,
        'marka'           => true,
        'tedarikci'       => true,
        'satis_fiyati'    => true,
        'indirimli_fiyat' => true,
        'stok_adedi'      => true,
        'kdv_dahil'       => true,
        'kdv_orani'       => true,
        'seo'             => true,
        'uye_tipi_fiyat'  => true,
        'resimler'        => true,
        'aktif'           => true,
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
                $this->hasMore = count($batch) === $this->perPage;
            } else {
                // Arama tek seferde tüm sonuçları döner (sayfalama gereksiz)
                $batch = $ana->searchProductsByStokKodu($q);
                $this->hasMore = false;
                $this->page = 1;
            }
            $this->products = $this->flatten($batch);
            $this->resultCount = count($this->products);
            if ($this->resultCount === 0) {
                $this->error = $q === '' ? 'Ana mağazada ürün yok.' : 'Aramaya uyan ürün bulunamadı.';
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
    }
    public function hicbirini(): void
    {
        foreach (array_keys($this->fields) as $k) $this->fields[$k] = false;
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
        $selectedFields = array_keys(array_filter($this->fields));
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
                    $bayi->updateProductSelective($payload, $bayiId, $varMap, $selectedFields);
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
