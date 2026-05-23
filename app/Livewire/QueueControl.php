<?php

namespace App\Livewire;

use App\Models\SyncJob;
use Illuminate\Support\Facades\Artisan;
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
    public int $pendingJobs = 0;
    public int $runningSyncJobs = 0;
    public ?string $statusMsg = null;

    public function mount(): void
    {
        $this->refreshCounts();
    }

    #[On('queue-changed')]
    public function refreshCounts(): void
    {
        // jobs tablosu (database queue driver) — yoksa 0
        try {
            $this->pendingJobs = (int) DB::table('jobs')->count();
        } catch (\Throwable) {
            $this->pendingJobs = 0;
        }
        $this->runningSyncJobs = (int) SyncJob::where('status', 'running')->count();
    }

    /**
     * Soft + hard stop: queue:restart (graceful exit), jobs tablosunu temizle,
     * çalışan SyncJob satırlarını failed/cancelled olarak işaretle.
     */
    public function stopAll(): void
    {
        // 1) Bekleyen job'ları temizle
        $cleared = 0;
        try {
            $cleared = (int) DB::table('jobs')->count();
            DB::table('jobs')->delete();
        } catch (\Throwable) {
        }

        // 2) Failed_jobs tablosundakileri de istersek temizleyebiliriz — yapmıyoruz, audit kalsın

        // 3) Worker'lara graceful restart sinyali (cache flag) — bir sonraki iterasyonda exit eder
        Artisan::call('queue:restart');

        // 4) Çalışan SyncJob satırlarını manuel kapat
        $cancelled = SyncJob::where('status', 'running')->update([
            'status' => 'failed',
            'finished_at' => now(),
            'last_error' => 'Kullanıcı tarafından manuel durduruldu',
        ]);

        $this->refreshCounts();
        $this->statusMsg = "Kuyruk durduruldu: {$cleared} bekleyen iş silindi, {$cancelled} çalışan iş iptal edildi.";
    }

    public function render()
    {
        return view('livewire.queue-control');
    }
}
