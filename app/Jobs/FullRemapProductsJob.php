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
use Throwable;

/**
 * BİR KERELİK tam eşleştirme (harita kurma) job'u.
 *
 * Amaç: ana + bayi'deki TÜM ürünleri çekip stok_kodu (birincil) / barkod
 * (yedek) ile eşleştirip product_mappings tablosunu eksiksiz doldurmak.
 *
 * ÜRÜN OLUŞTURMAZ veya GÜNCELLEMEZ — yalnızca lokal haritayı kurar. Böylece
 * delta job'ları (SyncNewProductsJob / SyncStockPriceJob) artık SOAP probe'a
 * düşmeden lokal lookup yapar ve sadece gerçekten yeni/değişen ürünlerle ilgilenir.
 *
 * Eşleşmeyenler:
 *   - ana'da var, bayi'de yok → mapping satırı bayi_* NULL, status='pending'
 *     (bu ürünler bayide eksik; istenirse ayrı bir "eksikleri oluştur" pası ile
 *      aktarılabilir). error_count'a yazılır (rapor amaçlı, gerçek hata değil).
 *
 * Tamamlanınca her iki delta checkpoint'i now()'a çekilir: harita şu an taze
 * olduğundan, bundan sonra sadece İLERİYE dönük yeni/değişen ürünler işlenir.
 */
class FullRemapProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** Tüm katalog taraması — 3 saat tampon. */
    public int $timeout = 10800;

    public function middleware(): array
    {
        return [(new WithoutOverlapping('full-remap'))->dontRelease()->expireAfter(11100)];
    }

    public function handle(): void
    {
        $job = SyncJob::create([
            'type' => 'product_remap',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $ana = ProductService::for('ana');
            $bayi = ProductService::for('bayi');

            // ── 1) Bayi indeksini kur: stok_kodu/barkod → [cardId, varId, barkod] ──
            [$bayiByStok, $bayiByBarkod, $bayiVarCount, $stoppedEarly] = $this->buildBayiIndex($bayi, $job);

            if ($stoppedEarly) {
                $this->finish($job, 'failed', 'Kullanıcı tarafından durduruldu (bayi indeks aşamasında)');

                return;
            }

            // ── 2) Ana ürünleri gez, eşleştir, mapping upsert ──
            $matched = 0;
            $unmatched = 0;
            $anaVarCount = 0;
            $page = 1;
            $perPage = (int) config('ticimax.batch_size', 100);
            $consecutiveBugPages = 0;
            $maxConsecutiveBugPages = 10;

            while (true) {
                if (QueueControl::isStopRequested($job->id)) {
                    $stoppedEarly = true;
                    break;
                }

                // since=null → MIN_DATETIME → TÜM ürünler (created filtresi tüm kataloğu döndürür).
                $result = $ana->fetchProductPageRecovering('created', null, $page, $perPage, 'ASC');
                $products = $result['products'];

                if ($result['bug']) {
                    $consecutiveBugPages++;
                    if (empty($products) && $consecutiveBugPages >= $maxConsecutiveBugPages) {
                        break;
                    }
                } else {
                    $consecutiveBugPages = 0;
                    if (empty($products)) {
                        break;
                    }
                }

                foreach ($products as $urunKarti) {
                    if (QueueControl::isStopRequested($job->id)) {
                        $stoppedEarly = true;
                        break 2;
                    }
                    $anaCardId = (int) ($urunKarti['ID'] ?? 0);
                    foreach ($this->extractVariants($urunKarti) as $v) {
                        $stok = (string) ($v['StokKodu'] ?? '');
                        $barkod = (string) ($v['Barkod'] ?? '');
                        if ($stok === '' && $barkod === '') {
                            continue;
                        }
                        $anaVarCount++;

                        $hit = ($stok !== '' ? ($bayiByStok[$stok] ?? null) : null)
                            ?? ($barkod !== '' ? ($bayiByBarkod[$barkod] ?? null) : null);

                        $this->upsertMapping(
                            $stok,
                            $barkod,
                            $anaCardId,
                            (int) ($v['ID'] ?? 0),
                            $hit,
                            (float) ($v['SatisFiyati'] ?? 0),
                            (int) ($v['StokAdedi'] ?? 0),
                        );

                        $hit ? $matched++ : $unmatched++;
                    }
                }

                // İlerleme görünür olsun (QueueControl her sayfada günceller).
                $job->update(['total' => $anaVarCount, 'success_count' => $matched, 'error_count' => $unmatched]);

                if (! $result['bug'] && count($products) < $perPage) {
                    break;
                }
                $page++;
            }

            // ── 3) Özet log + checkpoint ──
            SyncLog::create([
                'job_id' => $job->id,
                'action' => 'remap',
                'direction' => 'ana_to_bayi',
                'status' => 'success',
                'message' => "Harita kuruldu — ana varyasyon: {$anaVarCount}, eşleşen: {$matched}, "
                    ."eşleşmeyen (bayide yok): {$unmatched}, bayi varyasyon: {$bayiVarCount}.",
            ]);

            if (! $stoppedEarly) {
                // Harita taze → delta'lar bundan sonra yalnızca ileriye baksın.
                SyncSetting::put(SyncNewProductsJob::LAST_RUN_KEY, now()->toIso8601String());
                SyncSetting::put(SyncStockPriceJob::LAST_RUN_KEY, now()->toDateTimeString());
            }

            $this->finish(
                $job,
                $stoppedEarly ? 'failed' : 'completed',
                $stoppedEarly ? 'Kullanıcı tarafından durduruldu' : null,
                $anaVarCount,
                $matched,
                $unmatched,
            );
        } catch (Throwable $e) {
            $this->finish($job, 'failed', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Bayi'deki tüm ürünleri sayfalayıp stok_kodu/barkod indeksleri kur.
     *
     * @return array{0: array<string,array>, 1: array<string,array>, 2: int, 3: bool}
     *                                                                                [bayiByStok, bayiByBarkod, bayiVarCount, stoppedEarly]
     */
    protected function buildBayiIndex(ProductService $bayi, SyncJob $job): array
    {
        $byStok = [];
        $byBarkod = [];
        $count = 0;
        $page = 1;
        $perPage = (int) config('ticimax.batch_size', 100);
        $consecutiveBugPages = 0;
        $maxConsecutiveBugPages = 10;
        $stoppedEarly = false;

        while (true) {
            if (QueueControl::isStopRequested($job->id)) {
                $stoppedEarly = true;
                break;
            }

            $result = $bayi->fetchProductPageRecovering('created', null, $page, $perPage, 'ASC');
            $products = $result['products'];

            if ($result['bug']) {
                $consecutiveBugPages++;
                if (empty($products) && $consecutiveBugPages >= $maxConsecutiveBugPages) {
                    break;
                }
            } else {
                $consecutiveBugPages = 0;
                if (empty($products)) {
                    break;
                }
            }

            foreach ($products as $urunKarti) {
                $cardId = (int) ($urunKarti['ID'] ?? 0);
                foreach ($this->extractVariants($urunKarti) as $v) {
                    $vId = (int) ($v['ID'] ?? 0);
                    if ($vId <= 0) {
                        continue;
                    }
                    $stok = (string) ($v['StokKodu'] ?? '');
                    $barkod = (string) ($v['Barkod'] ?? '');
                    $entry = ['card_id' => $cardId, 'var_id' => $vId, 'barkod' => $barkod, 'stok' => $stok];
                    if ($stok !== '') {
                        $byStok[$stok] = $entry;
                    }
                    if ($barkod !== '') {
                        $byBarkod[$barkod] = $entry;
                    }
                    $count++;
                }
            }

            if (! $result['bug'] && count($products) < $perPage) {
                break;
            }
            $page++;
        }

        return [$byStok, $byBarkod, $count, $stoppedEarly];
    }

    /**
     * Tek varyasyon için mapping satırı upsert et. $hit doluysa bayi ID'leri yazılır
     * (status=synced); boşsa bayi tarafı NULL kalır (status=pending → bayide eksik).
     *
     * @param  array|null  $hit  ['card_id'=>int,'var_id'=>int,'barkod'=>string,'stok'=>string]|null
     */
    protected function upsertMapping(
        string $stok,
        string $barkod,
        int $anaCardId,
        int $anaVarId,
        ?array $hit,
        float $price,
        int $stock,
    ): void {
        $key = $stok !== '' ? ['stok_kodu' => $stok] : ['barcode' => $barkod];

        ProductMapping::updateOrCreate($key, [
            'stok_kodu' => $stok ?: null,
            'barcode' => $barkod ?: null,
            'ana_product_id' => $anaCardId > 0 ? (string) $anaCardId : null,
            'ana_variant_id' => $anaVarId > 0 ? (string) $anaVarId : null,
            'bayi_product_id' => $hit ? (string) $hit['card_id'] : null,
            'bayi_variant_id' => $hit ? (string) $hit['var_id'] : null,
            'last_price' => $price,
            'last_stock' => $stock,
            'status' => $hit ? 'synced' : 'pending',
            'last_error' => null,
            'last_synced_at' => now(),
        ]);
    }

    protected function extractVariants(array $urunKarti): array
    {
        $v = $urunKarti['Varyasyonlar'] ?? [];
        if (isset($v['Varyasyon'])) {
            $v = is_array($v['Varyasyon']) && array_is_list($v['Varyasyon']) ? $v['Varyasyon'] : [$v['Varyasyon']];
        }

        return is_array($v) ? array_values($v) : [];
    }

    protected function finish(SyncJob $job, string $status, ?string $error, ?int $total = null, ?int $success = null, ?int $errorCount = null): void
    {
        $data = ['status' => $status, 'finished_at' => now(), 'last_error' => $error];
        if ($total !== null) {
            $data['total'] = $total;
        }
        if ($success !== null) {
            $data['success_count'] = $success;
        }
        if ($errorCount !== null) {
            $data['error_count'] = $errorCount;
        }
        $job->update($data);
    }
}
