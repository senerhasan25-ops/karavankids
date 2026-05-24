<?php

namespace App\Livewire;

use App\Models\SyncJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use Symfony\Component\Process\Process;

/**
 * Navigation bar'da her sayfada görünen "Kuyruk Durdur" denetimi.
 * - Bekleyen job sayısı (jobs tablosu)
 * - Çalışan SyncJob sayısı (sync_jobs.status = running)
 * - "Şimdi Durdur" butonu: graceful restart sinyali + jobs tablosu temizliği +
 *   çalışan SyncJob satırlarını "cancelled" olarak işaretle.
 *
 * Worker zaten ölmüş olabilir — yine de güvenli (no-op).
 */
class QueueControl extends Component
{
    /** Cache key — job'lar bu flag'i her ürün/sipariş arasında okur */
    public const STOP_FLAG_KEY = 'sync_stop_requested';

    public int $pendingJobs = 0;
    public int $runningSyncJobs = 0;
    public bool $stopRequested = false;
    public ?string $statusMsg = null;

    public function mount(): void
    {
        $this->refreshCounts();
    }

    #[On('queue-changed')]
    public function refreshCounts(): void
    {
        try {
            $this->pendingJobs = (int) DB::table('jobs')->count();
        } catch (\Throwable) {
            $this->pendingJobs = 0;
        }
        $this->runningSyncJobs = (int) SyncJob::where('status', 'running')->count();
        $this->stopRequested = (bool) Cache::get(self::STOP_FLAG_KEY, false);
    }

    /**
     * Soft + hard stop: queue:restart (graceful exit), jobs tablosunu temizle,
     * çalışan SyncJob satırlarını failed/cancelled olarak işaretle.
     */
    public function stopAll(): void
    {
        $log = []; // Her adımın sonucunu topla, hangisinin başarısız olduğunu kullanıcıya göster

        // 1) IN-JOB STOP FLAG
        try {
            Cache::put(self::STOP_FLAG_KEY, true, now()->addHour());
            $log[] = '✓ Stop flag yollandı';
        } catch (\Throwable $e) {
            $log[] = '✗ Cache flag: ' . $e->getMessage();
        }

        // 2) Bekleyen job'ları sil
        $cleared = 0;
        try {
            $cleared = (int) DB::table('jobs')->count();
            DB::table('jobs')->delete();
            $log[] = "✓ {$cleared} bekleyen iş silindi";
        } catch (\Throwable $e) {
            $log[] = '✗ Jobs sil: ' . $e->getMessage();
        }

        // 3) DB'deki "running" SyncJob satırlarını ZORLA failed işaretle
        $cancelled = 0;
        try {
            $cancelled = SyncJob::where('status', 'running')->update([
                'status' => 'failed',
                'finished_at' => now(),
                'last_error' => 'Kullanıcı tarafından zorla durduruldu',
            ]);
            $log[] = "✓ {$cancelled} çalışan SyncJob → failed";
        } catch (\Throwable $e) {
            $log[] = '✗ SyncJob update: ' . $e->getMessage();
        }

        // 4) queue:restart sinyali
        try {
            Artisan::call('queue:restart');
            $log[] = '✓ queue:restart sinyali';
        } catch (\Throwable $e) {
            $log[] = '✗ queue:restart: ' . $e->getMessage();
        }

        // 5) Worker process'lerini öldür (opsiyonel — hata olsa bile diğer adımlar tamamlanmış olmalı)
        try {
            $killed = $this->killQueueWorkerProcesses();
            $log[] = $killed > 0 ? "✓ {$killed} worker process öldürüldü" : '· Worker process bulunamadı';
        } catch (\Throwable $e) {
            $log[] = '✗ Worker kill: ' . $e->getMessage();
        }

        $this->refreshCounts();
        $this->statusMsg = implode(' | ', $log);
    }

    /**
     * Çalışan `php artisan queue:work` process'lerini Windows üzerinde bulup öldürür.
     * Linux/Mac için `pkill -f "queue:work"` fallback'i de denenir.
     *
     * @return int Öldürülen process sayısı
     */
    protected function killQueueWorkerProcesses(): int
    {
        $killed = 0;

        if (PHP_OS_FAMILY === 'Windows') {
            // wmic ile queue:work içeren php.exe process'lerini bul
            $process = new Process([
                'powershell.exe',
                '-NoProfile',
                '-Command',
                "Get-WmiObject Win32_Process -Filter \"name='php.exe'\" | "
                . "Where-Object { \$_.CommandLine -like '*queue:work*' } | "
                . "ForEach-Object { Stop-Process -Id \$_.ProcessId -Force; Write-Output \$_.ProcessId }",
            ]);
            $process->setTimeout(15);
            try {
                $process->run();
                $output = trim($process->getOutput());
                if ($output !== '') {
                    $killed = count(array_filter(explode("\n", $output)));
                }
            } catch (\Throwable) {
                // sessiz geç
            }
        } else {
            // Linux/Mac
            $process = new Process(['pkill', '-f', 'queue:work']);
            $process->setTimeout(10);
            try {
                $process->run();
                if ($process->isSuccessful()) {
                    $killed = 1; // pkill exact count vermiyor, tahmin
                }
            } catch (\Throwable) {
            }
        }

        return $killed;
    }

    /**
     * Stop flag'ini sıfırla — yeni sync başlatmadan önce çağrılmalı, yoksa job hemen çıkar.
     * runNow tarafından otomatik temizlenir.
     */
    public function clearStopFlag(): void
    {
        Cache::forget(self::STOP_FLAG_KEY);
        $this->refreshCounts();
        $this->statusMsg = 'Durdur flag temizlendi — yeni sync başlatılabilir.';
    }

    public function render()
    {
        return view('livewire.queue-control');
    }
}
