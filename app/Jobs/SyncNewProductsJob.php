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

/**
 * Ana → Bayi ürün sync.
 *
 * UPSERT: ProductMapper TedarikciKodu = "SUP|{anaId}|{stokKodu}" yazar; ProductService
 * SaveUrun çağrısında `TedarikciKodunaGoreGuncelle: true` flag'i ile gönderir →
 * Ticimax mevcutsa günceller, yoksa yeni oluşturur. Lokal eşleşme tablosuna gerek YOK.
 *
 * ProductMapping tablosu sadece audit/dashboard için tutulur (kaç sync, son hata vs.).
 */
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

            $since = $this->since;
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

    protected function processOne(SyncJob $job, array $urunKarti, ProductService $bayi, ProductMapper $mapper): void
    {
        $anaUrunId = (string) ($urunKarti['ID'] ?? '');
        $stokKodu = $mapper->resolveStokKodu($urunKarti);
        $tedKodu = $mapper->buildTedarikciKodu((int) $anaUrunId, $stokKodu);
        $primaryBarcode = $this->primaryBarcode($urunKarti);
        $urunAdi = (string) ($urunKarti['UrunAdi'] ?? '');

        $context = [
            'barcode' => $primaryBarcode ?: null,
            'stok_kodu' => $stokKodu ?: null,
            'urun_adi' => $urunAdi ?: null,
            'ana_id' => $anaUrunId ?: null,
        ];

        if ($stokKodu === '' && $primaryBarcode === '') {
            $this->logError($job, $context, "Üründe ne StokKodu ne Barkod var (ID={$anaUrunId})");
            return;
        }

        try {
            $payload = $mapper->anaToBayiCreatePayload($urunKarti);
            $bayi->createProduct($payload);

            ProductMapping::updateOrCreate(
                ['barcode' => $primaryBarcode ?: $tedKodu],
                [
                    'ana_product_id' => $anaUrunId,
                    'bayi_product_id' => null,
                    'last_price' => $this->primaryPrice($urunKarti),
                    'last_stock' => $this->primaryStock($urunKarti),
                    'status' => 'synced',
                    'last_error' => null,
                    'last_synced_at' => now(),
                ]
            );

            $this->logSuccess($job, $context, "TedarikciKodu={$tedKodu}");
        } catch (Throwable $e) {
            // Ham SOAP XML'i client'tan çek (hata anında ne gönderdik, ne döndü)
            $client = $bayi->getClient();
            $this->logError(
                $job,
                $context,
                $e->getMessage(),
                $client->getLastRequestXml(),
                $client->getLastResponseXml()
            );
            ProductMapping::updateOrCreate(
                ['barcode' => $primaryBarcode ?: $tedKodu],
                ['ana_product_id' => $anaUrunId, 'status' => 'error', 'last_error' => $e->getMessage()]
            );
        }
    }

    protected function primaryBarcode(array $urunKarti): string
    {
        $variants = $this->extractVariants($urunKarti);
        return trim((string) ($variants[0]['Barkod'] ?? $urunKarti['Barkod'] ?? ''));
    }

    protected function primaryPrice(array $urunKarti): float
    {
        $variants = $this->extractVariants($urunKarti);
        return (float) ($variants[0]['SatisFiyati'] ?? $urunKarti['SatisFiyati'] ?? 0);
    }

    protected function primaryStock(array $urunKarti): int
    {
        $variants = $this->extractVariants($urunKarti);
        return (int) ($variants[0]['StokAdedi'] ?? $urunKarti['StokAdedi'] ?? 0);
    }

    protected function extractVariants(array $urunKarti): array
    {
        $v = $urunKarti['Varyasyonlar'] ?? [];
        if (isset($v['Varyasyon'])) {
            $v = is_array($v['Varyasyon']) && array_is_list($v['Varyasyon']) ? $v['Varyasyon'] : [$v['Varyasyon']];
        }
        return is_array($v) ? array_values($v) : [];
    }

    protected function logSuccess(SyncJob $job, array $ctx, string $msg): void
    {
        $job->increment('success_count');
        SyncLog::create(array_merge($ctx, [
            'job_id' => $job->id,
            'action' => 'create_product',
            'direction' => 'ana_to_bayi',
            'status' => 'success',
            'message' => $msg,
        ]));
    }

    protected function logError(SyncJob $job, array $ctx, string $msg, ?string $rawRequest = null, ?string $rawResponse = null): void
    {
        $job->increment('error_count');
        SyncLog::create(array_merge($ctx, [
            'job_id' => $job->id,
            'action' => 'create_product',
            'direction' => 'ana_to_bayi',
            'status' => 'error',
            'message' => $msg,
            'raw_request' => $rawRequest,
            'raw_response' => $rawResponse,
        ]));
    }
}
