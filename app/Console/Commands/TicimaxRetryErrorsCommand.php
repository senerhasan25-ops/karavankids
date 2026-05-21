<?php

namespace App\Console\Commands;

use App\Models\ProductMapping;
use App\Models\SyncJob;
use App\Models\SyncLog;
use App\Services\Ticimax\ProductMapper;
use App\Services\Ticimax\ProductService;
use Illuminate\Console\Command;
use Throwable;

/**
 * status='error' olan product_mappings kayıtlarını yeniden işler.
 * SyncNewProductsJob'ı tüm ürünler için baştan çalıştırmaktan çok daha hızlı.
 */
class TicimaxRetryErrorsCommand extends Command
{
    protected $signature = 'ticimax:retry-errors
        {--limit=200 : Tek seferde işlenecek maksimum mapping}
        {--barcode= : Sadece bu barkodu dene}';

    protected $description = 'product_mappings tablosundaki error kayıtlarını yeniden ana→bayi sync etmeyi dener.';

    public function handle(): int
    {
        $ana = ProductService::for('ana');
        $bayi = ProductService::for('bayi');
        $mapper = new ProductMapper();
        $mapper->setBrandResolver(fn (string $name) => $bayi->findOrCreateBrandId($name));
        $anaSupplierIdToName = array_flip($ana->getSupplierMap());
        $mapper->setSupplierResolver(function (int $anaId) use ($anaSupplierIdToName, $bayi) {
            $name = $anaSupplierIdToName[$anaId] ?? '';
            return $name ? $bayi->findOrCreateSupplierId($name) : 0;
        });

        $job = SyncJob::create([
            'type' => 'product_create',
            'status' => 'running',
            'started_at' => now(),
        ]);

        $query = ProductMapping::query();
        if ($this->option('barcode')) {
            $query->where('barcode', $this->option('barcode'));
        } else {
            $query->where('status', 'error');
        }

        $total = (clone $query)->count();
        $limit = (int) $this->option('limit');
        $errors = $query->limit($limit)->get();

        $this->info("İşlenecek: " . $errors->count() . " / toplam error: {$total}");

        $success = 0;
        $fail = 0;

        foreach ($errors as $mapping) {
            $job->increment('total');
            $bar = $mapping->barcode;
            try {
                $anaUrun = $ana->getProductByBarcode($bar);
                if (! $anaUrun) {
                    throw new \RuntimeException("Ana'da bulunamadı");
                }

                // Önce bayi'de var mı tekrar kontrol (önceki sync'te oluşmuş olabilir)
                $existing = $bayi->getProductByBarcode($bar);
                if ($existing && ! empty($existing['ID'])) {
                    $mapping->update([
                        'ana_product_id' => (string) ($anaUrun['ID'] ?? ''),
                        'bayi_product_id' => (string) $existing['ID'],
                        'status' => 'synced',
                        'last_error' => null,
                        'last_synced_at' => now(),
                    ]);
                    $this->logSuccess($job, $bar, 'matched_existing');
                    $success++;
                    $this->line("  ✓ {$bar} (eşleşti, ID={$existing['ID']})");
                    continue;
                }

                $payload = $mapper->anaToBayiCreatePayload($anaUrun);
                $created = $bayi->createProduct($payload);
                $newId = (int) ($created['ID'] ?? 0);

                // SaveUrun bazen 0 dönüyor — barkodla yeniden çek
                if ($newId === 0) {
                    $verify = $bayi->getProductByBarcode($bar);
                    $newId = (int) ($verify['ID'] ?? 0);
                }

                if ($newId === 0) {
                    throw new \RuntimeException('SaveUrun başarılı ama bayi\'de ürün bulunamadı (ID alınamadı)');
                }

                $mapping->update([
                    'ana_product_id' => (string) ($anaUrun['ID'] ?? ''),
                    'bayi_product_id' => (string) $newId,
                    'status' => 'synced',
                    'last_error' => null,
                    'last_synced_at' => now(),
                ]);
                $this->logSuccess($job, $bar, "created ID={$newId}");
                $success++;
                $this->line("  ✓ {$bar} (oluşturuldu, ID={$newId})");
            } catch (Throwable $e) {
                $mapping->update(['status' => 'error', 'last_error' => $e->getMessage()]);
                $this->logError($job, $bar, $e->getMessage());
                $fail++;
                $shortMsg = substr($e->getMessage(), 0, 100);
                $this->line("  ✗ {$bar} | {$shortMsg}");
            }
        }

        $job->update(['status' => 'completed', 'finished_at' => now()]);

        $this->newLine();
        $this->info("Bitti: başarılı={$success}, başarısız={$fail}");
        return self::SUCCESS;
    }

    protected function logSuccess(SyncJob $job, string $barcode, string $msg): void
    {
        $job->increment('success_count');
        SyncLog::create([
            'job_id' => $job->id, 'barcode' => $barcode,
            'action' => 'create_product', 'direction' => 'ana_to_bayi',
            'status' => 'success', 'message' => $msg,
        ]);
    }

    protected function logError(SyncJob $job, string $barcode, string $msg): void
    {
        $job->increment('error_count');
        SyncLog::create([
            'job_id' => $job->id, 'barcode' => $barcode,
            'action' => 'create_product', 'direction' => 'ana_to_bayi',
            'status' => 'error', 'message' => $msg,
        ]);
    }
}
