<?php

namespace App\Jobs;

use App\Livewire\QueueControl;
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

/**
 * Ana → Bayi stok/fiyat sync (LOKAL-ÖNCELİKLİ).
 *
 * Önceden: her varyasyon için bayi'ye SelectUrun(Barkod) atılıyordu → varyasyon
 * ID çıkarılıyordu → updateStock/updatePrice çağrılıyordu (2 SOAP / varyasyon).
 *
 * Şimdi: bayi_variant_id zaten ProductMapping'te → direkt updateStock/updatePrice
 * çağrılır. Bayi tarafında SelectUrun YOK (1 SOAP / varyasyon).
 *
 * Sadece ana'da ürün okuma için 1 SOAP gerek (güncel stok/fiyatı görmek için).
 */
class SyncStockPriceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public ?string $singleStokKodu = null)
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
                ->whereNotNull('bayi_variant_id')
                ->whereNotNull('stok_kodu');
            if ($this->singleStokKodu) {
                $query->where('stok_kodu', $this->singleStokKodu);
            }

            $stoppedEarly = false;
            $query->chunkById(100, function ($mappings) use ($job, $ana, $bayi, &$stoppedEarly) {
                foreach ($mappings as $m) {
                    if (QueueControl::isStopRequested($job->id)) {
                        $stoppedEarly = true;
                        return false; // chunkById'i durdur
                    }
                    $job->increment('total');
                    $this->processOne($job, $m, $ana, $bayi);
                }
                return null;
            });

            $job->update([
                'status' => $stoppedEarly ? 'failed' : 'completed',
                'finished_at' => now(),
                'last_error' => $stoppedEarly ? 'Kullanıcı tarafından manuel durduruldu' : null,
            ]);
        } catch (Throwable $e) {
            $job->update([
                'status' => 'failed',
                'finished_at' => now(),
                'last_error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected function processOne(SyncJob $job, ProductMapping $m, ProductService $ana, ProductService $bayi): void
    {
        $context = [
            'barcode' => $m->barcode,
            'stok_kodu' => $m->stok_kodu,
            'ana_id' => $m->ana_product_id,
            'bayi_id' => $m->bayi_product_id,
        ];

        try {
            // Kaynaktan güncel stok/fiyat çek
            $anaProduct = $ana->getProductByStokKodu($m->stok_kodu);
            if (! $anaProduct) {
                throw new \RuntimeException('Ana mağazada stok_kodu ile ürün bulunamadı');
            }
            $anaVar = $this->findVariantByStokKodu($anaProduct, $m->stok_kodu);
            if (! $anaVar) {
                throw new \RuntimeException('Ana ürün içinde eşleşen varyasyon yok');
            }

            $stock = (int) ($anaVar['StokAdedi'] ?? 0);
            $price = (float) ($anaVar['SatisFiyati'] ?? 0);
            $kdv = (float) ($anaVar['KdvOrani'] ?? 20);
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
                'last_stock' => $stock,
                'last_price' => $price,
                'last_synced_at' => now(),
                'status' => 'synced',
                'last_error' => null,
            ]);

            $this->log($job, $context, 'success', implode(' | ', $msgParts));
        } catch (Throwable $e) {
            $m->update(['status' => 'error', 'last_error' => $e->getMessage()]);
            $this->log($job, $context, 'error', $e->getMessage());
        }
    }

    protected function findVariantByStokKodu(array $urunKarti, string $stokKodu): ?array
    {
        $v = $urunKarti['Varyasyonlar'] ?? [];
        if (isset($v['Varyasyon'])) {
            $v = is_array($v['Varyasyon']) && array_is_list($v['Varyasyon']) ? $v['Varyasyon'] : [$v['Varyasyon']];
        }
        if (! is_array($v)) {
            return null;
        }
        foreach ($v as $vr) {
            if ((string) ($vr['StokKodu'] ?? '') === $stokKodu) {
                return $vr;
            }
        }
        return $v[0] ?? null;
    }

    protected function log(SyncJob $job, array $ctx, string $status, string $msg): void
    {
        if ($status === 'success') {
            $job->increment('success_count');
        } else {
            $job->increment('error_count');
        }
        SyncLog::create(array_merge($ctx, [
            'job_id' => $job->id,
            'action' => 'update_stock_price',
            'direction' => 'ana_to_bayi',
            'status' => $status,
            'message' => $msg,
        ]));
    }
}
