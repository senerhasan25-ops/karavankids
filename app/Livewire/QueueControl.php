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
     * Stop: worker process'ine DOKUNMAZ. Sadece:
     *  - Cache stop flag → çalışan job her SOAP çağrısından önce kontrol eder ve çıkar
     *  - jobs tablosu (bekleyenler) temizlenir
     *  - "running" SyncJob satırları failed işaretlenir (UI hemen güncellensin)
     *
     * Worker yaşamaya devam eder, yeni job dispatch edildiğinde alır.
     */
    public function stopAll(): void
    {
        $log = [];

        // 1) Cache stop flag — job'lar bunu görünce nazikçe çıkar
        try {
            Cache::put(self::STOP_FLAG_KEY, true, now()->addHour());
            $log[] = '✓ Stop flag yollandı';
        } catch (\Throwable $e) {
            $log[] = '✗ Cache flag: ' . $e->getMessage();
        }

        // 2) Bekleyen job'ları sil
        try {
            $cleared = (int) DB::table('jobs')->count();
            DB::table('jobs')->delete();
            $log[] = "✓ {$cleared} bekleyen iş silindi";
        } catch (\Throwable $e) {
            $log[] = '✗ Jobs sil: ' . $e->getMessage();
        }

        // 3) "running" SyncJob satırlarını failed → UI'da hemen 0 görünsün
        try {
            $cancelled = SyncJob::where('status', 'running')->update([
                'status' => 'failed',
                'finished_at' => now(),
                'last_error' => 'Kullanıcı tarafından manuel durduruldu',
            ]);
            $log[] = "✓ {$cancelled} çalışan SyncJob → failed";
        } catch (\Throwable $e) {
            $log[] = '✗ SyncJob update: ' . $e->getMessage();
        }

        $this->refreshCounts();
        $this->statusMsg = implode(' | ', $log) . ' — Worker mevcut SOAP çağrısını bitirir bitirmez çıkar.';
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
