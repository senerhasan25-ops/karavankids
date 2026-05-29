<?php

namespace App\Console\Commands;

use App\Models\SyncLog;
use Illuminate\Console\Command;

/**
 * sync_logs tablosunu tail -f gibi canlı izler.
 * Her saniye yeni satırları renkli basar — hata kırmızı, başarı yeşil.
 *
 * Kullanım:
 *   php artisan ticimax:tail              # her şey
 *   php artisan ticimax:tail --only=error # sadece hatalar
 *   php artisan ticimax:tail --direction=ana_to_bayi  # sadece ürün sync
 */
class TicimaxTailLogsCommand extends Command
{
    protected $signature = 'ticimax:tail
        {--only= : success | error | all (default: all)}
        {--direction= : ana_to_bayi | bayi_to_ana | (boş = hepsi)}
        {--interval=1 : Polling aralığı (saniye)}';

    protected $description = 'sync_logs tablosunu canlı tail eder (tail -f benzeri).';

    public function handle(): int
    {
        $only = $this->option('only') ?: 'all';
        $direction = $this->option('direction') ?: '';
        $interval = max(1, (int) $this->option('interval'));

        // Mevcut son ID'den itibaren başla — eski log'ları basma
        $lastId = (int) (SyncLog::max('id') ?? 0);

        $this->info("🔴 LIVE: sync_logs tail başladı (son ID: {$lastId}, aralık: {$interval}s)");
        $this->line(str_repeat('─', 100));

        while (true) {
            $query = SyncLog::where('id', '>', $lastId)->orderBy('id');
            if ($only === 'success' || $only === 'error') {
                $query->where('status', $only);
            }
            if ($direction) {
                $query->where('direction', $direction);
            }

            foreach ($query->get() as $log) {
                $time = $log->created_at?->format('H:i:s') ?? '--:--:--';
                $statusIcon = match ($log->status) {
                    'success' => '<fg=green>✓</>',
                    'error' => '<fg=red>✗</>',
                    default => '<fg=yellow>·</>',
                };
                $dir = $log->direction === 'ana_to_bayi' ? '→' : '←';
                $barcode = $log->barcode ?: '-';
                $action = str_pad($log->action ?: '-', 18);
                $msg = mb_substr((string) $log->message, 0, 80);

                $this->line(sprintf(
                    '<fg=gray>[%s]</> %s %s <fg=cyan>%s</> <fg=magenta>%s</> %s',
                    $time, $statusIcon, $dir, $action, $barcode, $msg
                ));

                $lastId = (int) $log->id;
            }

            sleep($interval);
        }
    }
}
