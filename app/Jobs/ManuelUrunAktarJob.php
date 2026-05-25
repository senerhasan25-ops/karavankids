<?php

namespace App\Jobs;

use App\Models\ProductMapping;
use App\Models\SyncJob;
use App\Models\SyncLog;
use App\Services\Ticimax\ProductMapper;
use App\Services\Ticimax\ProductService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Manuel Ürün Aktarımı arka plan işi.
 *
 * ProductPicker Livewire bileşenindeki yeniUrunleriAktar / stokFiyatGuncelle / aktar
 * metodları doğrudan SOAP çağrıları yapıyordu ve 30 saniyelik PHP timeout'una
 * takılıyordu. Bu job, işlemi queue worker'a taşır; Livewire anında "kuyruğa alındı"
 * mesajı döner, kullanıcı ilerlemeyi Loglar sayfasından izler.
 *
 * ROWS: _raw alanı olmayan kompakt satırlar (payload büyümesini önlemek için).
 *       Her mode, gerektiğinde Ana mağazadan ürünü SOAP ile yeniden çeker.
 */
class ManuelUrunAktarJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Manuel aktarım job'u için 2 saat limit (100 ürün ~10 dk, 1000 ürün ~100 dk). */
    public int $timeout = 7200;

    /** Manuel işler için tek deneme yeterli — retry mantıksız (kullanıcı tekrar başlatır) */
    public int $tries = 1;

    /**
     * @param string $mode      'yeni_urun' | 'stok_fiyat' | 'full_aktar'
     * @param array  $rows      Compact product rows — NO _raw field
     * @param array  $fields    Selected fields (full_aktar modunda kullanılır)
     * @param int    $syncJobId SyncJob.id (ilerleme + log için)
     */
    public function __construct(
        public readonly string $mode,
        public readonly array $rows,
        public readonly array $fields,
        public readonly int $syncJobId,
    ) {}

    public function handle(): void
    {
        $job = SyncJob::find($this->syncJobId);
        $job?->update(['status' => 'running', 'started_at' => now()]);

        try {
            match ($this->mode) {
                'yeni_urun'  => $this->handleYeniUrun($job),
                'stok_fiyat' => $this->handleStokFiyat($job),
                'full_aktar' => $this->handleFullAktar($job),
                default      => null,
            };
            $job?->update(['status' => 'completed', 'finished_at' => now()]);
        } catch (\Throwable $e) {
            $job?->update(['status' => 'failed', 'finished_at' => now()]);
            throw $e;
        }
    }

    /* -----------------------------------------------------------------------
     |  Mode: sadece yeni ürünleri aktar
     * --------------------------------------------------------------------- */

    private function handleYeniUrun(?SyncJob $job): void
    {
        $byUrunKarti = $this->groupByUrunKarti();

        // ── Adım 1: product_mappings'te bayi_product_id dolu olanları toplu bul ──
        // Bu ürünler zaten aktarılmış → SOAP çağrısı yapmadan atla.
        $stokKodulari = array_filter(array_column(array_values($byUrunKarti), 'stok_kodu'));
        $mevcutMapping = ProductMapping::whereIn('stok_kodu', $stokKodulari)
            ->whereNotNull('bayi_product_id')
            ->pluck('bayi_product_id', 'stok_kodu')
            ->all();

        // ── Adım 2: Mapping'de olmayan (gerçekten yeni olabilecek) ürünleri filtrele ──
        $adaylar = array_filter(
            $byUrunKarti,
            fn ($row) => ! isset($mevcutMapping[$row['stok_kodu'] ?? ''])
        );

        if (empty($adaylar)) {
            // Tüm ürünler zaten bayide — log kaydı bile oluşturma
            SyncLog::create([
                'job_id'    => $job?->id,
                'action'    => 'create_product',
                'direction' => 'ana_to_bayi',
                'status'    => 'skipped',
                'message'   => 'Listelenen tüm ürünler zaten bayide mevcut ('
                    . count($byUrunKarti) . ' ürün kart).',
            ]);
            return;
        }

        $ana    = ProductService::for('ana');
        $bayi   = ProductService::for('bayi');
        $mapper = $this->buildMapper($ana, $bayi);

        // ── Adım 3: Kalan adaylar için Bayi SOAP probe → gerçekten yoksa oluştur ──
        foreach ($adaylar as $row) {
            $stokKodu = $row['stok_kodu'] ?? '';
            $barkod   = $row['barkod']   ?? '';
            $urunAdi  = $row['urun_adi'] ?? '';

            if ($stokKodu === '') {
                continue;
            }
            $job?->increment('total');

            try {
                // Bayi SOAP probe — mapping'te yoktu ama Bayi'de olabilir (eski ürün vb.)
                $bayiMevcut = $bayi->getProductByStokKodu($stokKodu);
                if ($bayiMevcut && (int) ($bayiMevcut['ID'] ?? 0) > 0) {
                    // Bayide var ama mapping'te yoktu → mapping'i güncelle, atla
                    $this->upsertMapping($row, (int) $bayiMevcut['ID'],
                        $this->mapBayiVariantIds($bayiMevcut));
                    SyncLog::create($this->logData($job, 'create_product', 'skipped', $row,
                        'Bayide zaten var (ID=' . $bayiMevcut['ID'] . ') — mapping güncellendi'));
                    continue;
                }

                // Ana'dan ham ürün verisini çek
                $raw = $ana->getProductByStokKodu($stokKodu);
                if (! $raw || (int) ($raw['ID'] ?? 0) === 0) {
                    $job?->increment('error_count');
                    SyncLog::create($this->logData($job, 'create_product', 'error', $row,
                        'Ana mağazada ürün bulunamadı'));
                    continue;
                }

                $payload = $mapper->anaToBayiCreatePayload($raw);
                $created = $bayi->createProduct($payload);
                $bayiId  = (int) ($created['ID'] ?? 0);

                $msg = "Yeni ürün aktarıldı — stok kodu: {$stokKodu} | barkod: {$barkod} | bayi ID: {$bayiId}";
                $job?->increment('success_count');
                SyncLog::create($this->logData($job, 'create_product', 'success', $row, $msg));
                $this->upsertMapping($row, $bayiId, []);
            } catch (\Throwable $e) {
                $job?->increment('error_count');
                SyncLog::create($this->logData($job, 'create_product', 'error', $row,
                    substr($e->getMessage(), 0, 250)));
            }
        }
    }

    /* -----------------------------------------------------------------------
     |  Mode: sadece stok + fiyat güncelle
     * --------------------------------------------------------------------- */

    private function handleStokFiyat(?SyncJob $job): void
    {
        $ana    = ProductService::for('ana');
        $bayi   = ProductService::for('bayi');
        $mapper = $this->buildMapper($ana, $bayi);
        $fields = ['stok_adedi', 'satis_fiyati', 'indirimli_fiyat'];

        foreach ($this->groupByUrunKarti() as $row) {
            $stokKodu = $row['stok_kodu'] ?? '';
            $barkod   = $row['barkod']   ?? '';
            $urunAdi  = $row['urun_adi'] ?? '';

            if ($stokKodu === '') {
                continue;
            }
            $job?->increment('total');

            try {
                $bayiMevcut = $bayi->getProductByStokKodu($stokKodu);
                if (! $bayiMevcut || (int) ($bayiMevcut['ID'] ?? 0) === 0) {
                    SyncLog::create($this->logData($job, 'update_stock_price', 'skipped', $row,
                        'Bayide bulunamadı (önce aktar)'));
                    continue;
                }

                // Stok/fiyat payload'ı için Ana'dan ham veriyi çek
                $raw = $ana->getProductByStokKodu($stokKodu);
                if (! $raw) {
                    $job?->increment('error_count');
                    SyncLog::create($this->logData($job, 'update_stock_price', 'error', $row,
                        'Ana mağazada ürün bulunamadı'));
                    continue;
                }

                $payload = $mapper->anaToBayiCreatePayload($raw);
                $bayiId  = (int) $bayiMevcut['ID'];
                $varMap  = $this->mapBayiVariantIds($bayiMevcut);
                $bayi->updateProductSelective($payload, $bayiId, $varMap, $fields, $bayiMevcut);

                $stok  = $row['stok_adedi'] ?? 0;
                $fiyat = number_format((float) ($row['satis_fiyati'] ?? 0), 2, ',', '.');
                $msg   = "bayi ID={$bayiId} | stok={$stok} fiyat={$fiyat}";
                $job?->increment('success_count');
                SyncLog::create($this->logData($job, 'update_stock_price', 'success', $row, $msg));
                $this->upsertMapping($row, $bayiId, $varMap);
            } catch (\Throwable $e) {
                $job?->increment('error_count');
                SyncLog::create($this->logData($job, 'update_stock_price', 'error', $row,
                    substr($e->getMessage(), 0, 250)));
            }
        }
    }

    /* -----------------------------------------------------------------------
     |  Mode: tam aktarım (seçili parametrelere göre)
     * --------------------------------------------------------------------- */

    private function handleFullAktar(?SyncJob $job): void
    {
        $ana    = ProductService::for('ana');
        $bayi   = ProductService::for('bayi');
        $mapper = $this->buildMapper($ana, $bayi);

        foreach ($this->groupByUrunKarti() as $row) {
            $stokKodu = $row['stok_kodu'] ?? '';
            $barkod   = $row['barkod']   ?? '';
            $urunAdi  = $row['urun_adi'] ?? '';

            if ($stokKodu === '') {
                continue;
            }
            $job?->increment('total');

            try {
                $raw = $ana->getProductByStokKodu($stokKodu);
                if (! $raw || (int) ($raw['ID'] ?? 0) === 0) {
                    $job?->increment('error_count');
                    SyncLog::create($this->logData($job, 'transfer_product', 'error', $row,
                        'Ana mağazada ürün bulunamadı'));
                    continue;
                }

                $bayiMevcut = $bayi->getProductByStokKodu($stokKodu);
                $payload    = $mapper->anaToBayiCreatePayload($raw);

                if ($bayiMevcut && (int) ($bayiMevcut['ID'] ?? 0) > 0) {
                    $bayiId = (int) $bayiMevcut['ID'];
                    $varMap = $this->mapBayiVariantIds($bayiMevcut);
                    $bayi->updateProductSelective($payload, $bayiId, $varMap, $this->fields, $bayiMevcut);
                    $msg = "Güncellendi — bayi ID={$bayiId}";
                    $this->upsertMapping($row, $bayiId, $varMap);
                } else {
                    $created = $bayi->createProduct($payload);
                    $bayiId  = (int) ($created['ID'] ?? 0);
                    $msg     = "Oluşturuldu — bayi ID={$bayiId}";
                    $this->upsertMapping($row, $bayiId, []);
                }

                $job?->increment('success_count');
                SyncLog::create($this->logData($job, 'transfer_product', 'success', $row, $msg));
            } catch (\Throwable $e) {
                $job?->increment('error_count');
                SyncLog::create($this->logData($job, 'transfer_product', 'error', $row,
                    substr($e->getMessage(), 0, 250)));
            }
        }
    }

    /* -----------------------------------------------------------------------
     |  Yardımcılar
     * --------------------------------------------------------------------- */

    /**
     * Rows'u urun_karti_id bazında grupla — her kart için bir temsilci satır.
     * Böylece aynı ürün kartının birden fazla varyasyonu seçilmiş olsa da
     * SOAP çağrısı ve SaveUrun/güncelleme kartı bir kez işler.
     */
    private function groupByUrunKarti(): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            $uid = (int) ($row['urun_karti_id'] ?? 0);
            if ($uid > 0 && ! isset($out[$uid])) {
                $out[$uid] = $row;
            }
        }
        return $out;
    }

    protected function mapBayiVariantIds(array $bayiKart): array
    {
        $vars = $bayiKart['Varyasyonlar']['Varyasyon'] ?? $bayiKart['Varyasyonlar'] ?? [];
        if (isset($vars['Barkod'])) {
            $vars = [$vars];
        }
        $out = [];
        if (is_array($vars)) {
            foreach ($vars as $v) {
                $sk = (string) ($v['StokKodu'] ?? '');
                $id = (int) ($v['ID'] ?? 0);
                if ($sk !== '' && $id > 0) {
                    $out[$sk] = $id;
                }
            }
        }
        return $out;
    }

    protected function upsertMapping(array $row, int $bayiProductId, array $bayiVarMap): void
    {
        $stokKodu = $row['stok_kodu'] ?? '';
        if ($stokKodu === '') {
            return;
        }
        ProductMapping::updateOrCreate(
            ['stok_kodu' => $stokKodu],
            [
                'barcode'         => $row['barkod'] ?? null,
                'ana_variant_id'  => $row['variant_id'] ?? null,
                'bayi_product_id' => $bayiProductId ?: null,
                'bayi_variant_id' => $bayiVarMap[$stokKodu] ?? null,
                'last_price'      => $row['satis_fiyati'] ?? null,
                'last_stock'      => $row['stok_adedi'] ?? null,
                'status'          => 'synced',
                'last_error'      => null,
                'last_synced_at'  => now(),
            ]
        );
    }

    /** SyncLog::create() için standart veri dizisi üretir. */
    private function logData(?SyncJob $job, string $action, string $status, array $row, string $message): array
    {
        return [
            'job_id'    => $job?->id,
            'action'    => $action,
            'direction' => 'ana_to_bayi',
            'status'    => $status,
            'barcode'   => ($row['barkod'] ?? '') ?: null,
            'stok_kodu' => $row['stok_kodu'] ?? null,
            'urun_adi'  => $row['urun_adi'] ?? null,
            'ana_id'    => $row['urun_karti_id'] ?? null,
            'message'   => $message,
        ];
    }

    protected function buildMapper(ProductService $ana, ProductService $bayi): ProductMapper
    {
        $mapper            = new ProductMapper();
        $defaultBrandId    = $bayi->getDefaultBrandId();
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
            $id   = $name ? $bayi->findOrCreateSupplierId($name) : 0;
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
}
