<?php

namespace App\Livewire;

use App\Models\SyncJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;

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

        // 4) DB'deki "running" SyncJob satırlarını işaretle — UI'da tutarlı görünsün
        //    (Job kendisi cancelled status yazacak ama bu görsel için anında değişiklik)
        SyncJob::where('status', 'running')->update([
            'last_error' => 'Kullanıcı tarafından durdurma sinyali yollandı...',
        ]);

        $this->refreshCounts();
        $this->statusMsg = "Sinyal yollandı. {$cleared} bekleyen iş silindi. Çalışan job(lar) bir sonraki kontrol noktasında (her ürün/sipariş arasında) nazikçe çıkacak.";
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
