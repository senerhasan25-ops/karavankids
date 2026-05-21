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
use Illuminate\Support\Carbon;
use Throwable;

class SyncNewProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public ?Carbon $since = null, public ?Carbon $until = null)
    {
    }

    public function handle(): void
    {
        $job = SyncJob::create([
            'type' => 'product_create',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $ana = ProductService::for('ana');
            $bayi = ProductService::for('bayi');
            $mapper = new ProductMapper();

            $since = $this->since ?? Carbon::now()->subDays(7);
            $page = 1;
            $perPage = (int) config('ticimax.batch_size', 50);

            while (true) {
                $products = $ana->getNewProducts($since, $page, $perPage);
                if (empty($products)) {
                    break;
                }

                foreach ($products as $urunKarti) {
                    $job->increment('total');
                    $this->processOne($job, $urunKarti, $bayi, $mapper);
                }

                if (count($products) < $perPage) {
                    break;
                }
                $page++;
            }

            $job->update(['status' => 'completed', 'finished_at' => now()]);
        } catch (Throwable $e) {
            $job->update([
                'status' => 'failed',
                'finished_at' => now(),
                'last_error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Ticimax UrunKarti yapısında: barkodlar Varyasyon altında. Her varyasyonu ayrı mapping yazıyoruz.
     */
    protected function processOne(SyncJob $job, array $urunKarti, ProductService $bayi, ProductMapper $mapper): void
    {
        $anaUrunId = (string) ($urunKarti['ID'] ?? '');
        $varyasyonlar = $this->extractVariants($urunKarti);
        $primaryBarcode = $varyasyonlar[0]['Barkod'] ?? null;

        if (! $primaryBarcode) {
            $this->logError($job, null, "Üründe barkod yok (ID={$anaUrunId})");
            return;
        }

        try {
            $mapping = ProductMapping::firstOrNew(['barcode' => $primaryBarcode]);
            $isActive = (bool) ($urunKarti['Aktif'] ?? true);
            $action = '';

            if (! $mapping->bayi_product_id) {
                $existing = $bayi->getProductByBarcode($primaryBarcode);
                if ($existing && ! empty($existing['ID'])) {
                    $mapping->bayi_product_id = (string) $existing['ID'];
                    $action = 'matched_existing';
                } else {
                    if (! $isActive) {
                        $this->logError($job, $primaryBarcode, "Ana ürün pasif, bayi'de oluşturulmadı");
                        $mapping->fill([
                            'ana_product_id' => $anaUrunId,
                            'status' => 'pending',
                            'last_error' => 'Ana ürün pasif',
                        ])->save();
                        return;
                    }
                    $payload = $mapper->anaToBayiCreatePayload($urunKarti);
                    $created = $bayi->createProduct($payload);
                    $mapping->bayi_product_id = (string) ($created['ID'] ?? '');
                    $action = 'created';
                }
            } else {
                $payload = $mapper->anaToBayiCreatePayload($urunKarti);
                $bayi->updateProduct($mapping->bayi_product_id, $payload);
                $action = 'updated';
            }

            if (! $isActive && $mapping->bayi_product_id) {
                try {
                    $bayi->setActive($mapping->bayi_product_id, false);
                    $action .= '_deactivated';
                } catch (Throwable $e) {
                    // sessizce devam — bir sonraki sync'te yeniden denenebilir
                }
            }

            $mapping->ana_product_id = $anaUrunId;
            $mapping->last_price = (float) ($varyasyonlar[0]['SatisFiyati'] ?? 0);
            $mapping->last_stock = (int) ($varyasyonlar[0]['StokAdedi'] ?? 0);
            $mapping->status = 'synced';
            $mapping->last_error = null;
            $mapping->last_synced_at = now();
            $mapping->save();

            $this->logSuccess($job, $primaryBarcode, "UrunKarti {$action}");

            // Diğer varyasyonların kendi mapping kayıtları (aynı UrunKarti'ye bağlanır)
            for ($i = 1; $i < count($varyasyonlar); $i++) {
                $v = $varyasyonlar[$i];
                if (! ($v['Barkod'] ?? null)) {
                    continue;
                }
                ProductMapping::updateOrCreate(
                    ['barcode' => $v['Barkod']],
                    [
                        'ana_product_id' => $anaUrunId,
                        'bayi_product_id' => $mapping->bayi_product_id, // ana UrunKarti'nin bayi karşılığı
                        'last_price' => (float) ($v['SatisFiyati'] ?? 0),
                        'last_stock' => (int) ($v['StokAdedi'] ?? 0),
                        'status' => 'synced',
                        'last_synced_at' => now(),
                    ]
                );
            }
        } catch (Throwable $e) {
            $this->logError($job, $primaryBarcode, $e->getMessage());
            ProductMapping::updateOrCreate(
                ['barcode' => $primaryBarcode],
                ['status' => 'error', 'last_error' => $e->getMessage()]
            );
        }
    }

    /**
     * UrunKarti içinden varyasyon listesini düz array olarak çek.
     */
    protected function extractVariants(array $urunKarti): array
    {
        $v = $urunKarti['Varyasyonlar'] ?? [];
        if (isset($v['Varyasyon'])) {
            $v = is_array($v['Varyasyon']) && array_is_list($v['Varyasyon']) ? $v['Varyasyon'] : [$v['Varyasyon']];
        }
        return is_array($v) ? array_values($v) : [];
    }

    protected function logSuccess(SyncJob $job, ?string $barcode, string $msg): void
    {
        $job->increment('success_count');
        SyncLog::create([
            'job_id' => $job->id,
            'barcode' => $barcode,
            'action' => 'create_product',
            'direction' => 'ana_to_bayi',
            'status' => 'success',
            'message' => $msg,
        ]);
    }

    protected function logError(SyncJob $job, ?string $barcode, string $msg): void
    {
        $job->increment('error_count');
        SyncLog::create([
            'job_id' => $job->id,
            'barcode' => $barcode,
            'action' => 'create_product',
            'direction' => 'ana_to_bayi',
            'status' => 'error',
            'message' => $msg,
        ]);
    }
}
