<?php

namespace App\Jobs;

use App\Models\ProductMapping;
use App\Models\SyncJob;
use App\Models\SyncLog;
use App\Services\Ticimax\ProductService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncStockPriceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public ?string $singleBarcode = null)
    {
    }

    public function handle(): void
    {
        $job = SyncJob::create([
            'type' => 'stock_price_update',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $ana = ProductService::for('ana');
            $bayi = ProductService::for('bayi');

            $query = ProductMapping::query()
                ->whereNotNull('bayi_product_id')
                ->where('status', '!=', 'error');
            if ($this->singleBarcode) {
                $query->where('barcode', $this->singleBarcode);
            }

            $query->chunkById(100, function ($mappings) use ($job, $ana, $bayi) {
                foreach ($mappings as $m) {
                    $job->increment('total');
                    try {
                        $anaProduct = $ana->getProductByBarcode($m->barcode);
                        if (! $anaProduct) {
                            throw new \RuntimeException('Ana mağazada ürün bulunamadı');
                        }
                        $stock = (int) ($anaProduct['StokAdedi'] ?? 0);
                        $price = (float) ($anaProduct['SatisFiyati'] ?? 0);

                        $bayi->updateStockAndPrice($m->bayi_product_id, $stock, $price);

                        $m->update([
                            'last_stock' => $stock,
                            'last_price' => $price,
                            'last_synced_at' => now(),
                            'status' => 'synced',
                            'last_error' => null,
                        ]);
                        $this->log($job, $m->barcode, 'success', "Stok={$stock}, Fiyat={$price}");
                    } catch (Throwable $e) {
                        $m->update(['status' => 'error', 'last_error' => $e->getMessage()]);
                        $this->log($job, $m->barcode, 'error', $e->getMessage());
                    }
                }
            });

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

    protected function log(SyncJob $job, string $barcode, string $status, string $msg): void
    {
        if ($status === 'success') {
            $job->increment('success_count');
        } else {
            $job->increment('error_count');
        }
        SyncLog::create([
            'job_id' => $job->id,
            'barcode' => $barcode,
            'action' => 'update_stock_price',
            'direction' => 'ana_to_bayi',
            'status' => $status,
            'message' => $msg,
        ]);
    }
}
