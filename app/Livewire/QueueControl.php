<?php

namespace App\Livewire;

use App\Models\SyncJob;
use App\Models\SyncSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Navigation bar'da her sayfada görünen "Kuyruk Durdur" denetimi.
 *
 * - Bekleyen job'lar (jobs tablosu) ayrı ayrı listelenir, her birinin yanında ✕ butonu
 * - Çalışan SyncJob satırları ayrı ayrı listelenir, her birinin yanında ⛔ butonu
 * - "Hepsini Durdur" → toplu durdurma (global stop flag + jobs temizliği)
 *
 * Worker process'ine DOKUNULMAZ. Stop sinyali Cache flag üzerinden iletilir;
 * çalışan job mevcut SOAP çağrısını bitirip sonraki ürün/sipariş öncesi çıkar.
 */
class QueueControl extends Component
{
    /** Cache key — global stop (tüm running job'lar bunu görür) */
    public const STOP_FLAG_KEY = 'sync_stop_requested';

    /** Per-job stop flag prefix → tek bir running SyncJob'ı durdurmak için */
    public const STOP_FLAG_JOB_PREFIX = 'sync_stop_job_';

    public array $pendingJobs = [];     // [{id, label, queue, attempts, created_at}]
    public array $runningJobs = [];     // [{id, type, started_at, success, error, total}]
    public bool $stopRequested = false;
    public bool $autoSyncEnabled = false;
    public ?string $statusMsg = null;

    public function mount(): void
    {
        $this->refreshCounts();
    }

    #[On('queue-changed')]
    public function refreshCounts(): void
    {
        // BEKLEYEN — jobs tablosu
        try {
            $rows = DB::table('jobs')
                ->select(['id', 'queue', 'attempts', 'created_at', 'payload'])
                ->orderBy('id')
                ->limit(50)
                ->get();
            $this->pendingJobs = $rows->map(function ($r) {
                $payload = json_decode($r->payload ?? '{}', true);
                $cmdName = $payload['displayName'] ?? ($payload['data']['commandName'] ?? 'Job');
                $short = class_basename($cmdName);
                return [
                    'id' => (int) $r->id,
                    'label' => $short,
                    'queue' => $r->queue,
                    'attempts' => (int) $r->attempts,
                    'created_at' => $r->created_at,
                ];
            })->toArray();
        } catch (\Throwable) {
            $this->pendingJobs = [];
        }

        // ÇALIŞAN — sync_jobs.status='running'
        $this->runningJobs = SyncJob::where('status', 'running')
            ->orderBy('id')
            ->limit(50)
            ->get(['id', 'type', 'started_at', 'success_count', 'error_count', 'total'])
            ->map(fn ($j) => [
                'id' => $j->id,
                'type' => $j->type,
                'started_at' => $j->started_at?->diffForHumans(),
                'success' => (int) $j->success_count,
                'error' => (int) $j->error_count,
                'total' => (int) $j->total,
                'stop_pending' => (bool) Cache::get(self::STOP_FLAG_JOB_PREFIX . $j->id, false),
            ])
            ->toArray();

        $this->stopRequested = (bool) Cache::get(self::STOP_FLAG_KEY, false);
        $this->autoSyncEnabled = (bool) SyncSetting::get('otomatik_aktif', false);
    }

    /** Scheduler tarafından otomatik tetiklenen sync'i tek tıkla kapat/aç. */
    public function toggleAutoSync(): void
    {
        $new = ! $this->autoSyncEnabled;
        SyncSetting::put('otomatik_aktif', $new);
        $this->autoSyncEnabled = $new;
        $this->statusMsg = $new
            ? '✓ Otomatik sync AÇILDI — scheduler her dakika kontrol eder.'
            : '✓ Otomatik sync KAPATILDI — yeni job dispatch edilmez.';
    }

    /** Job'un içinden çağrılır — global VEYA per-job flag varsa true. */
    public static function isStopRequested(?int $syncJobId = null): bool
    {
        if (Cache::get(self::STOP_FLAG_KEY, false)) {
            return true;
        }
        if ($syncJobId !== null && Cache::get(self::STOP_FLAG_JOB_PREFIX . $syncJobId, false)) {
            return true;
        }
        return false;
    }

    /** Tek bir bekleyen job'ı sil. */
    public function cancelPending(int $id): void
    {
        try {
            $deleted = DB::table('jobs')->where('id', $id)->delete();
            $this->statusMsg = $deleted ? "✓ Bekleyen job #{$id} silindi" : "· Job #{$id} bulunamadı (zaten alınmış olabilir)";
        } catch (\Throwable $e) {
            $this->statusMsg = '✗ ' . $e->getMessage();
        }
        $this->refreshCounts();
    }

    /** Tek bir çalışan SyncJob'a stop sinyali yolla. */
    public function cancelRunning(int $syncJobId): void
    {
        try {
            Cache::put(self::STOP_FLAG_JOB_PREFIX . $syncJobId, true, now()->addHour());
            // UI'da hemen failed görünsün — worker SOAP'ı bitirince zaten kendi de güncelleyecek
            SyncJob::where('id', $syncJobId)->where('status', 'running')->update([
                'status' => 'failed',
                'finished_at' => now(),
                'last_error' => 'Kullanıcı tarafından manuel durduruldu',
            ]);
            $this->statusMsg = "✓ SyncJob #{$syncJobId} durdur sinyali yollandı (SOAP biter bitmez çıkar)";
        } catch (\Throwable $e) {
            $this->statusMsg = '✗ ' . $e->getMessage();
        }
        $this->refreshCounts();
    }

    /** Hepsini birden durdur. */
    public function stopAll(): void
    {
        $log = [];
        try {
            Cache::put(self::STOP_FLAG_KEY, true, now()->addHour());
            $log[] = '✓ Global stop flag';
        } catch (\Throwable $e) {
            $log[] = '✗ ' . $e->getMessage();
        }
        try {
            $cleared = (int) DB::table('jobs')->count();
            DB::table('jobs')->delete();
            $log[] = "✓ {$cleared} bekleyen silindi";
        } catch (\Throwable $e) {
            $log[] = '✗ ' . $e->getMessage();
        }
        try {
            $cancelled = SyncJob::where('status', 'running')->update([
                'status' => 'failed',
                'finished_at' => now(),
                'last_error' => 'Kullanıcı tarafından manuel durduruldu (toplu)',
            ]);
            $log[] = "✓ {$cancelled} çalışan → failed";
        } catch (\Throwable $e) {
            $log[] = '✗ ' . $e->getMessage();
        }

        $this->refreshCounts();
        $this->statusMsg = implode(' | ', $log);
    }

    /** Stop flag'ini sıfırla — yeni sync başlatmadan önce çağrılmalı. */
    public function clearStopFlag(): void
    {
        Cache::forget(self::STOP_FLAG_KEY);
        // Per-job flag'leri de temizle — DB taraması gereksiz, sadece global yeter aslında
        // ama görünür running yokken kalıntı varsa diye temizleyelim
        $this->refreshCounts();
        $this->statusMsg = 'Global stop flag temizlendi — yeni sync başlatılabilir.';
    }

    public function render()
    {
        return view('livewire.queue-control');
    }
}
