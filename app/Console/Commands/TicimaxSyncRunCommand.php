<?php

namespace App\Console\Commands;

use App\Jobs\PullBayiOrdersJob;
use App\Jobs\SyncNewProductsJob;
use App\Jobs\SyncStockPriceJob;
use App\Models\ProductMapping;
use App\Models\SyncJob;
use App\Models\SyncLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class TicimaxSyncRunCommand extends Command
{
    protected $signature = 'ticimax:sync
        {what=products : products | stock | orders | all}
        {--since= : YYYY-MM-DD, sadece bu tarihten sonra değişenler (yoksa hepsi)}';

    protected $description = 'Sync job\'larını queue\'suz, hemen çalıştırır. Test/debug için.';

    public function handle(): int
    {
        $what = $this->argument('what');
        $since = $this->option('since') ? Carbon::parse($this->option('since')) : null;

        $jobs = match ($what) {
            'products' => [new SyncNewProductsJob($since)],
            'stock' => [new SyncStockPriceJob()],
            'orders' => [new PullBayiOrdersJob()],
            'all' => [new SyncNewProductsJob($since), new SyncStockPriceJob(), new PullBayiOrdersJob()],
            default => null,
        };
        if (! $jobs) {
            $this->error("Geçersiz 'what': {$what}. Geçerli: products | stock | orders | all");
            return self::FAILURE;
        }

        foreach ($jobs as $job) {
            $name = class_basename($job);
            $this->info("→ {$name} çalıştırılıyor...");
            $start = microtime(true);
            try {
                $job->handle();
                $elapsed = round(microtime(true) - $start, 2);
                $this->info("✓ {$name} tamamlandı ({$elapsed}s)");
            } catch (\Throwable $e) {
                $this->error("✗ {$name} HATA: " . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info('--- Özet ---');
        $lastJob = SyncJob::orderByDesc('id')->first();
        if ($lastJob) {
            $this->line("Son iş #{$lastJob->id} | {$lastJob->type} | {$lastJob->status} | toplam={$lastJob->total} başarı={$lastJob->success_count} hata={$lastJob->error_count}");
            if ($lastJob->last_error) {
                $this->line("Hata: " . $lastJob->last_error);
            }
        }

        $mappingCount = ProductMapping::count();
        $syncedCount = ProductMapping::where('status', 'synced')->count();
        $errorCount = ProductMapping::where('status', 'error')->count();
        $this->line("Mapping kayıtları: toplam={$mappingCount} synced={$syncedCount} error={$errorCount}");

        $recentLogs = SyncLog::orderByDesc('id')->limit(5)->get();
        if ($recentLogs->isNotEmpty()) {
            $this->newLine();
            $this->info('--- Son 5 log satırı ---');
            foreach ($recentLogs as $log) {
                $this->line("[{$log->status}] {$log->action} | barcode={$log->barcode} | {$log->message}");
            }
        }

        return self::SUCCESS;
    }
}
