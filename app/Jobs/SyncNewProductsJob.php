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

    public function __construct(public ?Carbon $since = null)
    {
    }

    public function handle(ProductService $unused = null): void
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

                foreach ($products as $p) {
                    $job->increment('total');
                    $barcode = $p['Barkod'] ?? null;
                    if (! $barcode) {
                        $this->logSkip($job, null, 'Barkod yok, atlandı');
                        continue;
                    }

                    try {
                        $mapping = ProductMapping::firstOrNew(['barcode' => $barcode]);

                        if (! $mapping->bayi_product_id) {
                            $existing = $bayi->getProductByBarcode($barcode);
                            if ($existing && ! empty($existing['UrunKartiID'])) {
                                $mapping->bayi_product_id = (string) $existing['UrunKartiID'];
                            } else {
                                $payload = $mapper->anaToBayiCreatePayload($p);
                                $created = $bayi->createProduct($payload);
                                $mapping->bayi_product_id = (string) ($created['UrunKartiID'] ?? $created['Id'] ?? '');
                            }
                        }

                        $mapping->ana_product_id = (string) ($p['UrunKartiID'] ?? $p['Id'] ?? '');
                        $mapping->last_price = (float) ($p['SatisFiyati'] ?? 0);
                        $mapping->last_stock = (int) ($p['StokAdedi'] ?? 0);
                        $mapping->status = 'synced';
                        $mapping->last_error = null;
                        $mapping->last_synced_at = now();
                        $mapping->save();

                        $this->logSuccess($job, $barcode, 'Ürün eşleştirildi/oluşturuldu');
                    } catch (Throwable $e) {
                        $this->logError($job, $barcode, $e->getMessage());
                        ProductMapping::updateOrCreate(
                            ['barcode' => $barcode],
                            ['status' => 'error', 'last_error' => $e->getMessage()]
                        );
                    }
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

    protected function logSkip(SyncJob $job, ?string $barcode, string $msg): void
    {
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
