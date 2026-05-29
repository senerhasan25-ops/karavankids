<?php

namespace App\Jobs;

use App\Livewire\QueueControl;
use App\Models\ProductMapping;
use App\Models\SyncJob;
use App\Models\SyncLog;
use App\Models\SyncSetting;
use App\Services\Ticimax\ProductService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Ana → Bayi stok/fiyat delta sync (DEĞİŞİKLİK BAZLI).
 *
 * Tam tarama yerine sadece son checkpoint'ten beri Ana'da DuzenlemeTarihi
 * güncellenen ürünleri çeker. Değişiklik yoksa SOAP çağrısı yapılmaz.
 *
 * Checkpoint: sync_settings.'last_stock_price_run_at'
 *   - İlk çalışmada değer yoksa son 24 saate bakılır (güvenli başlangıç).
 *   - Job başarıyla tamamlanırsa $startedAt kaydedilir (race condition koruması).
 *   - Başarısız / durdurulursa checkpoint güncellenmez → bir sonraki çalışmada
 *     yeniden tarananır.
 *
 * Bayi güncelleme: updateStockBatch + updatePriceBatch (tek SOAP çağrısı).
 * Çok sayıda ürün değişmişse sayfalı çekilir (batch_size = 50).
 */
class SyncStockPriceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;
    /** Delta modunda çok sayıda ürün değişmişse 3 saat yeterli tampon. */
    public int $timeout = 10800;

    /** sync_settings anahtarı — stok/fiyat sync checkpoint. */
    public const LAST_RUN_KEY = 'last_stock_price_run_at';

    /**
     * @param  string|null  $singleStokKodu  Sadece bu ürünü güncelle (manuel tetik için).
     *                                        null → tam delta sync.
     * @param  Carbon|null  $forceSince      Checkpoint'i yoksay, buradan tara (test/debug).
     */
    public function __construct(
        public ?string $singleStokKodu = null,
        public ?Carbon $forceSince = null,
    ) {}

    /**
     * Delta sync uzun sürebiliyor; overlap kilidi ile scheduler'ın üst üste
     * kopya açması engellenir. singleStokKodu (manuel tek ürün) modunda da aynı
     * kilit paylaşılır — zaten kısa sürer, çakışma olası değil.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('sync-stock-price'))->dontRelease()->expireAfter(11100)];
    }

    public function handle(): void
    {
        $job = SyncJob::create([
            'type'       => 'stock_price_update',
            'status'     => 'running',
            'started_at' => now(),
        ]);

        $startedAt = now();

        try {
            $ana  = ProductService::for('ana');
            $bayi = ProductService::for('bayi');

            if ($this->singleStokKodu) {
                $this->handleSingle($job, $ana, $bayi);
                return;
            }

            $this->handleDelta($job, $ana, $bayi, $startedAt);
        } catch (Throwable $e) {
            $job->update([
                'status'      => 'failed',
                'finished_at' => now(),
                'last_error'  => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /* -----------------------------------------------------------------------
     |  Delta modu — yalnızca değişen ürünler
     * --------------------------------------------------------------------- */

    private function handleDelta(SyncJob $job, ProductService $ana, ProductService $bayi, Carbon $startedAt): void
    {
        // ── Checkpoint belirle ──────────────────────────────────────────────
        if ($this->forceSince) {
            $since = $this->forceSince;
        } else {
            $raw   = SyncSetting::get(self::LAST_RUN_KEY);
            $since = $raw ? Carbon::parse($raw) : now()->subDay();
        }

        $perPage      = (int) config('ticimax.batch_size', 50);
        $page         = 1;
        $stoppedEarly = false;

        // ── Tüm mapping'leri tek sorguda belleğe al ─────────────────────────
        // 1000 ürün için ~1 MB — yönetilebilir. Her SOAP döngüsünde DB query YOK.
        $mappings = ProductMapping::whereNotNull('bayi_variant_id')
            ->whereNotNull('stok_kodu')
            ->get()
            ->keyBy('stok_kodu');

        // ── Ana'dan değişen ürünleri sayfa sayfa çek ────────────────────────
        $consecutiveErrors = 0;
        $maxConsecutiveErrors = 10;
        while (true) {
            if (QueueControl::isStopRequested($job->id)) {
                $stoppedEarly = true;
                break;
            }

            try {
                // FiyatStokGuncellemeTarihi filtresine geçtik — sadece o tarihten
                // sonra fiyat veya stok değişen ürünler gelir.
                $products = $ana->getProductsByStockOrPriceChanged($since, $page, $perPage);
                $consecutiveErrors = 0;
            } catch (Throwable $e) {
                if ($ana->isTicimaxPaginationBug($e)) {
                    // Ticimax pagination bug — sayfayı atla, devam et.
                    // (Detaylı açıklama: SyncNewProductsJob içinde aynı pattern.)
                    SyncLog::create([
                        'job_id' => $job->id,
                        'action' => 'update_stock_price',
                        'direction' => 'ana_to_bayi',
                        'status' => 'warning',
                        'message' => "Ticimax SOAP page bug — sayfa #{$page} atlandı, ~{$perPage} ürün kaçırılmış olabilir.",
                    ]);
                    $consecutiveErrors++;
                    if ($consecutiveErrors >= $maxConsecutiveErrors) {
                        break;
                    }
                    $page++;
                    continue;
                }
                throw $e;
            }
            if (empty($products)) {
                break;
            }

            $this->processPage($job, $bayi, $products, $mappings);

            if (count($products) < $perPage) {
                break;
            }
            $page++;
        }

        // ── Checkpoint'i güncelle (sadece başarılı tamamlamada) ─────────────
        if (! $stoppedEarly) {
            SyncSetting::put(self::LAST_RUN_KEY, $startedAt->toDateTimeString());
        }

        $job->update([
            'status'      => $stoppedEarly ? 'failed' : 'completed',
            'finished_at' => now(),
            'last_error'  => $stoppedEarly ? 'Kullanıcı tarafından manuel durduruldu' : null,
        ]);
    }

    /**
     * Tek SOAP sayfasındaki ürünleri işle:
     *  - Varyasyonları mapping'le eşleştir
     *  - Gerçekten değişenleri batch dizilerine ekle
     *  - Tek updateStockBatch + updatePriceBatch SOAP çağrısı
     */
    private function processPage(SyncJob $job, ProductService $bayi, array $products, $mappings): void
    {
        $stockBatch = [];
        $priceBatch = [];
        $toUpdate   = [];  // stok_kodu → meta

        foreach ($products as $urunKarti) {
            foreach ($this->extractVariants($urunKarti) as $v) {
                $stokKodu = (string) ($v['StokKodu'] ?? '');
                if ($stokKodu === '') {
                    continue;
                }

                $m = $mappings->get($stokKodu);
                if (! $m || ! $m->bayi_variant_id) {
                    continue; // bu varyasyon bayi'ye eşleşmemiş, atla
                }

                $stock    = (int) ($v['StokAdedi'] ?? 0);
                $price    = (float) ($v['SatisFiyati'] ?? 0);
                $kdv      = (float) ($v['KdvOrani'] ?? 20);
                $kdvDahil = (bool) ($v['KdvDahil'] ?? true);

                $stockChanged = $m->last_stock === null || (int) $m->last_stock !== $stock;
                $priceChanged = $m->last_price === null || abs((float) $m->last_price - $price) > 0.01;

                if (! $stockChanged && ! $priceChanged) {
                    continue; // DuzenlemeTarihi güncellendi ama stok/fiyat aynı (başka alan değişti)
                }

                $job->increment('total');

                if ($stockChanged) {
                    $vi = ['ID' => (int) $m->bayi_variant_id, 'StokAdedi' => $stock];
                    if ($m->barcode) {
                        $vi['Barkod'] = $m->barcode;
                    }
                    $stockBatch[] = $vi;
                }

                if ($priceChanged && $m->barcode) {
                    $priceBatch[] = [
                        'Barkod'      => $m->barcode,
                        'SatisFiyati' => $price,
                        'KdvOrani'    => $kdv,
                        'KdvDahil'    => $kdvDahil,
                    ];
                }

                $toUpdate[$stokKodu] = [
                    'stock'        => $stock,
                    'price'        => $price,
                    'mapping'      => $m,
                    'stockChanged' => $stockChanged,
                    'priceChanged' => $priceChanged,
                ];
            }
        }

        if (empty($toUpdate)) {
            return; // bu sayfada güncelleme gerektiren ürün yok
        }

        // ── Bayi'ye toplu gönder ────────────────────────────────────────────
        $batchError = null;
        try {
            if (! empty($stockBatch)) {
                $bayi->updateStockBatch($stockBatch);
            }
            if (! empty($priceBatch)) {
                $bayi->updatePriceBatch($priceBatch);
            }
        } catch (Throwable $e) {
            $batchError = $e->getMessage();
        }

        // ── Sonuçları logla + mapping güncelle ─────────────────────────────
        foreach ($toUpdate as $stokKodu => $data) {
            $m   = $data['mapping'];
            $ctx = [
                'barcode'   => $m->barcode,
                'stok_kodu' => $stokKodu,
                'ana_id'    => $m->ana_product_id,
                'bayi_id'   => $m->bayi_product_id,
            ];

            if ($batchError === null) {
                $parts = [];
                if ($data['stockChanged']) {
                    $parts[] = 'stok ' . ($m->last_stock ?? '?') . '→' . $data['stock'];
                }
                if ($data['priceChanged']) {
                    $parts[] = 'fiyat ' . ($m->last_price ?? '?') . '→' . number_format($data['price'], 2);
                }

                $job->increment('success_count');
                $this->log($job, $ctx, 'success', implode(' | ', $parts));
                $m->update([
                    'last_stock'     => $data['stock'],
                    'last_price'     => $data['price'],
                    'status'         => 'synced',
                    'last_error'     => null,
                    'last_synced_at' => now(),
                ]);
            } else {
                $job->increment('error_count');
                $this->log($job, $ctx, 'error', 'Batch hatası: ' . substr($batchError, 0, 250));
                $m->update(['status' => 'error', 'last_error' => $batchError]);
            }
        }
    }

    /* -----------------------------------------------------------------------
     |  Tek ürün modu (manuel tetik, $singleStokKodu dolu)
     * --------------------------------------------------------------------- */

    private function handleSingle(SyncJob $job, ProductService $ana, ProductService $bayi): void
    {
        $stoppedEarly = false;

        ProductMapping::whereNotNull('bayi_variant_id')
            ->whereNotNull('stok_kodu')
            ->where('stok_kodu', $this->singleStokKodu)
            ->chunkById(100, function ($chunk) use ($job, $ana, $bayi, &$stoppedEarly) {
                foreach ($chunk as $m) {
                    if (QueueControl::isStopRequested($job->id)) {
                        $stoppedEarly = true;

                        return false;
                    }
                    $job->increment('total');
                    $this->processOne($job, $m, $ana, $bayi);
                }
            });

        $job->update([
            'status'      => $stoppedEarly ? 'failed' : 'completed',
            'finished_at' => now(),
            'last_error'  => $stoppedEarly ? 'Kullanıcı tarafından manuel durduruldu' : null,
        ]);
    }

    /* -----------------------------------------------------------------------
     |  Yardımcılar
     * --------------------------------------------------------------------- */

    /**
     * Tek ürün için Ana'dan veri çekip karşılaştır, değiştiyse Bayi'ye yaz.
     * Yalnızca singleStokKodu modunda kullanılır.
     */
    protected function processOne(SyncJob $job, ProductMapping $m, ProductService $ana, ProductService $bayi): void
    {
        $context = [
            'barcode'   => $m->barcode,
            'stok_kodu' => $m->stok_kodu,
            'ana_id'    => $m->ana_product_id,
            'bayi_id'   => $m->bayi_product_id,
        ];

        try {
            $anaProduct = $ana->getProductByStokKodu($m->stok_kodu);
            if (! $anaProduct) {
                throw new \RuntimeException('Ana mağazada stok_kodu ile ürün bulunamadı');
            }
            $anaVar = $this->findVariantByStokKodu($anaProduct, $m->stok_kodu);
            if (! $anaVar) {
                throw new \RuntimeException('Ana ürün içinde eşleşen varyasyon yok');
            }

            $stock    = (int) ($anaVar['StokAdedi'] ?? 0);
            $price    = (float) ($anaVar['SatisFiyati'] ?? 0);
            $kdv      = (float) ($anaVar['KdvOrani'] ?? 20);
            $kdvDahil = (bool) ($anaVar['KdvDahil'] ?? true);

            $stockChanged = $m->last_stock === null || (int) $m->last_stock !== $stock;
            $priceChanged = $m->last_price === null || abs((float) $m->last_price - $price) > 0.01;

            $msgParts = [];
            if ($stockChanged) {
                $bayi->updateStock((string) $m->bayi_variant_id, $stock, $m->barcode);
                $msgParts[] = 'stok ' . ($m->last_stock ?? '-') . "→{$stock}";
            }
            if ($priceChanged && $m->barcode) {
                $bayi->updatePrice($m->barcode, $price, $kdv, $kdvDahil);
                $msgParts[] = sprintf('fiyat %s→%.2f', $m->last_price ?? '-', $price);
            }
            if (empty($msgParts)) {
                $msgParts[] = "değişiklik yok (stok={$stock} fiyat=" . number_format($price, 2) . ')';
            }

            $m->update([
                'last_stock'     => $stock,
                'last_price'     => $price,
                'last_synced_at' => now(),
                'status'         => 'synced',
                'last_error'     => null,
            ]);

            $job->increment('success_count');
            $this->log($job, $context, 'success', implode(' | ', $msgParts));
        } catch (Throwable $e) {
            $job->increment('error_count');
            $m->update(['status' => 'error', 'last_error' => $e->getMessage()]);
            $this->log($job, $context, 'error', $e->getMessage());
        }
    }

    protected function extractVariants(array $urunKarti): array
    {
        $v = $urunKarti['Varyasyonlar'] ?? [];
        if (isset($v['Varyasyon'])) {
            $v = is_array($v['Varyasyon']) && array_is_list($v['Varyasyon']) ? $v['Varyasyon'] : [$v['Varyasyon']];
        }

        return is_array($v) ? array_values($v) : [];
    }

    protected function findVariantByStokKodu(array $urunKarti, string $stokKodu): ?array
    {
        foreach ($this->extractVariants($urunKarti) as $vr) {
            if ((string) ($vr['StokKodu'] ?? '') === $stokKodu) {
                return $vr;
            }
        }

        return $this->extractVariants($urunKarti)[0] ?? null;
    }

    protected function log(SyncJob $job, array $ctx, string $status, string $msg): void
    {
        SyncLog::create(array_merge($ctx, [
            'job_id'    => $job->id,
            'action'    => 'update_stock_price',
            'direction' => 'ana_to_bayi',
            'status'    => $status,
            'message'   => $msg,
        ]));
    }
}
