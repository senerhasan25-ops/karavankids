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
        // 1) IN-JOB STOP FLAG — uzun süren job'lar (SyncNewProductsJob 2469 ürün dönerken)
        //    her ürün arasında bu flag'i kontrol eder, true ise nazikçe çıkar.
        Cache::put(self::STOP_FLAG_KEY, true, now()->addHour());

        // 2) Bekleyen job'ları sil (henüz alınmamış olanlar)
        $cleared = 0;
        try {
            $cleared = (int) DB::table('jobs')->count();
            DB::table('jobs')->delete();
        } catch (\Throwable) {
        }

        // 3) Worker'a graceful restart sinyali (job arası kontrol noktasında çıkar)
        Artisan::call('queue:restart');

        // 4) DB'deki "running" SyncJob satırlarını ZORLA failed işaretle.
        //    Worker process'i hâlâ çalışıyorsa stop flag'i ile nazikçe çıkar; ama
        //    UI ve sayaçlar anında doğru görünmeli (çoğu durumda bu satırlar zaten
        //    ölü worker'lardan kalma orphan kayıtlar).
        $cancelled = SyncJob::where('status', 'running')->update([
            'status' => 'failed',
            'finished_at' => now(),
            'last_error' => 'Kullanıcı tarafından zorla durduruldu',
        ]);

        // 5) Eski / takılmış stop flag'leri için de jobs tablosunda kalan deferred job'ları sil
        try {
            DB::table('failed_jobs')->where('failed_at', '<', now()->subDay())->delete();
        } catch (\Throwable) {
        }

        // 6) ZORLA KAPATMA — çalışan queue:work PHP process'lerini Windows tasklist ile bul ve öldür
        //    Graceful stop sinyali yetmiyor (worker mevcut job ortasında flag'i göremiyor)
        $killed = $this->killQueueWorkerProcesses();

        $this->refreshCounts();
        $this->statusMsg = "Durduruldu: {$cleared} bekleyen + {$cancelled} çalışan iş kapatıldı. "
                         . ($killed > 0
                             ? "{$killed} queue worker process'i zorla sonlandırıldı."
                             : "Worker process bulunamadı (zaten kapalı veya farklı kullanıcı).");
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
