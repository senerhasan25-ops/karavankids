<?php

namespace App\Livewire;

use App\Jobs\ManuelUrunAktarJob;
use App\Models\SyncJob;
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
     *
     * Önce product_mappings tablosunu kontrol eder: bayi_product_id dolu = zaten aktarılmış.
     * Sadece mapping'de olmayan ürünleri job'a gönderir; hiç aday yoksa bilgi mesajı verir.
     * İşlemi arka plan job'una devreder — HTTP timeout riski ortadan kalkar.
     */
    public function yeniUrunleriAktar(): void
    {
        $this->results = [];
        $this->status  = null;
        $this->error   = null;

        if (empty($this->products)) {
            $this->error = 'Listede ürün yok — önce "Listele" tıkla.';
            return;
        }

        // urun_karti_id bazında grupla (her kart için bir temsilci)
        $byUrunKarti = [];
        foreach ($this->products as $r) {
            $uid = (int) $r['urun_karti_id'];
            if ($uid > 0 && ! isset($byUrunKarti[$uid])) {
                $byUrunKarti[$uid] = $r;
            }
        }

        // product_mappings'te bayi_product_id dolu olanları tek sorguda bul → zaten aktarılmış
        $stokKodulari  = array_filter(array_column(array_values($byUrunKarti), 'stok_kodu'));
        $mevcutMapping = \App\Models\ProductMapping::whereIn('stok_kodu', $stokKodulari)
            ->whereNotNull('bayi_product_id')
            ->pluck('stok_kodu')
            ->all();
        $mevcutSet = array_flip($mevcutMapping);

        // Sadece mapping'de olmayan adaylar
        $adaylar = array_values(array_filter(
            $byUrunKarti,
            fn ($r) => ! isset($mevcutSet[$r['stok_kodu'] ?? ''])
        ));

        $mevcutSayisi = count($byUrunKarti) - count($adaylar);

        if (empty($adaylar)) {
            $this->status = 'Listede ' . count($byUrunKarti) . ' ürün kart var, hepsi zaten bayide mevcut. Aktarılacak yeni ürün yok.';
            return;
        }

        // _raw alanını çıkar — job payload'ı küçük tutmak için Ana'dan yeniden çeker
        $compactRows = array_map(fn ($r) => array_diff_key($r, ['_raw' => true]), $adaylar);

        $job = SyncJob::create([
            'type'       => 'product_create',
            'status'     => 'pending',
            'started_at' => null,
        ]);

        ManuelUrunAktarJob::dispatch('yeni_urun', $compactRows, [], $job->id);

        $this->status = count($compactRows) . ' yeni ürün kuyruğa alındı'
            . ($mevcutSayisi > 0 ? " ({$mevcutSayisi} tanesi zaten bayide, atlandı)" : '')
            . ' — İş #' . $job->id . '. İlerlemeyi Loglar sayfasından takip edebilirsin.';
    }

    /**
     * Seçili ürünlerin sadece STOK ve FİYAT bilgilerini bayide günceller.
     * İşlemi arka plan job'una devreder — HTTP timeout riski ortadan kalkar.
     */
    public function stokFiyatGuncelle(): void
    {
        $this->results = [];
        $this->status  = null;

        if (empty($this->selected)) {
            $this->error = 'Güncellemek için ürün seçin.';
            return;
        }
        $this->error = null;

        $rows = array_values(array_filter(
            $this->products,
            fn ($r) => in_array((string) $r['variant_id'], $this->selected, true)
        ));
        // _raw alanını çıkar — job, Ana'dan yeniden çeker
        $compactRows = array_map(fn ($r) => array_diff_key($r, ['_raw' => true]), $rows);

        $job = SyncJob::create([
            'type'       => 'stock_price_update',
            'status'     => 'pending',
            'started_at' => null,
        ]);

        ManuelUrunAktarJob::dispatch('stok_fiyat', $compactRows, [], $job->id);

        $this->status = count($compactRows) . ' satır stok/fiyat güncellemesi kuyruğa alındı '
            . '— İş #' . $job->id . '. İlerlemeyi Loglar sayfasından takip edebilirsin.';
        $this->selected  = [];
        $this->selectAll = false;
    }

    /**
     * Seçili ürünleri belirlenen parametrelere göre bayi'ye aktar.
     * İşlemi arka plan job'una devreder — HTTP timeout riski ortadan kalkar.
     */
    public function aktar(): void
    {
        $this->results = [];
        $this->status  = null;

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

        $rows = array_values(array_filter(
            $this->products,
            fn ($r) => in_array((string) $r['variant_id'], $this->selected, true)
        ));
        if (empty($rows)) {
            $this->error = 'Seçili ürünler listede bulunamadı.';
            return;
        }
        // _raw alanını çıkar — job, Ana'dan yeniden çeker
        $compactRows = array_map(fn ($r) => array_diff_key($r, ['_raw' => true]), $rows);

        $job = SyncJob::create([
            'type'       => 'product_create',
            'status'     => 'pending',
            'started_at' => null,
        ]);

        ManuelUrunAktarJob::dispatch('full_aktar', $compactRows, $selectedFields, $job->id);

        $this->status = count($compactRows) . ' ürün aktarımı kuyruğa alındı '
            . '— İş #' . $job->id . '. İlerlemeyi Loglar sayfasından takip edebilirsin.';
        $this->selected  = [];
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

    public function render()
    {
        return view('livewire.product-picker');
    }
}
