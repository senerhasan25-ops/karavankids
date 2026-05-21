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

                        // Stok ve fiyat varyasyon seviyesinde — birincil varyasyondan al
                        $anaVar = $this->primaryVariant($anaProduct);
                        if (! $anaVar) {
                            throw new \RuntimeException('Ana ürünün varyasyonu yok');
                        }
                        $stock = (int) ($anaVar['StokAdedi'] ?? 0);
                        $price = (float) ($anaVar['SatisFiyati'] ?? 0);
                        $kdv = (float) ($anaVar['KdvOrani'] ?? 20);
                        $kdvDahil = (bool) ($anaVar['KdvDahil'] ?? true);

                        // Bayi tarafının VARYASYON ID'sini bul (UrunKartiID değil!)
                        $bayiProduct = $bayi->getProductByBarcode($m->barcode);
                        $bayiVar = $bayiProduct ? $this->primaryVariant($bayiProduct) : null;
                        if (! $bayiVar) {
                            throw new \RuntimeException('Bayi ürün/varyasyon bulunamadı');
                        }
                        $bayiVarId = (string) ($bayiVar['ID'] ?? 0);
                        $oldStock = (int) ($bayiVar['StokAdedi'] ?? 0);
                        $oldPrice = (float) ($bayiVar['SatisFiyati'] ?? 0);

                        // Sadece değişiklik varsa güncelle (gereksiz API call'ı önle)
                        $stockChanged = $oldStock !== $stock;
                        $priceChanged = abs($oldPrice - $price) > 0.01;

                        if ($stockChanged) {
                            $bayi->updateStock($bayiVarId, $stock, $m->barcode);
                        }
                        if ($priceChanged) {
                            $bayi->updatePrice($m->barcode, $price, $kdv, $kdvDahil);
                        }

                        $m->update([
                            'last_stock' => $stock,
                            'last_price' => $price,
                            'last_synced_at' => now(),
                            'status' => 'synced',
                            'last_error' => null,
                        ]);

                        $msgParts = [];
                        if ($stockChanged) {
                            $msgParts[] = "stok {$oldStock}→{$stock}";
                        }
                        if ($priceChanged) {
                            $msgParts[] = sprintf('fiyat %.2f→%.2f', $oldPrice, $price);
                        }
                        if (empty($msgParts)) {
                            $msgParts[] = "değişiklik yok (stok={$stock} fiyat=" . number_format($price, 2) . ')';
                        }
                        $this->log($job, $m->barcode, 'success', implode(' | ', $msgParts));
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

    /**
     * UrunKarti içinden birincil varyasyonu çıkar (SOAP wrapper'ı handle ederek).
     */
    protected function primaryVariant(array $urunKarti): ?array
    {
        $v = $urunKarti['Varyasyonlar'] ?? [];
        if (isset($v['Varyasyon'])) {
            $v = is_array($v['Varyasyon']) && array_is_list($v['Varyasyon']) ? $v['Varyasyon'] : [$v['Varyasyon']];
        }
        if (! is_array($v) || empty($v)) {
            return null;
        }
        return is_array($v[0] ?? null) ? $v[0] : null;
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
