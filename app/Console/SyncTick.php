<?php

namespace App\Console;

use App\Jobs\PullBayiOrdersJob;
use App\Jobs\SyncNewProductsJob;
use App\Jobs\SyncStockPriceJob;
use App\Livewire\QueueControl;
use App\Models\SyncSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class SyncTick
{
    public static function run(): void
    {
        if (! (bool) SyncSetting::get('otomatik_aktif', false)) {
            return;
        }

        // Global stop flag aktifse scheduler hiçbir yeni job dispatch ETMESİN.
        // Aksi halde kullanıcı "Durdur" diyor, scheduler 1 dk sonra yenisini açıyor.
        if (Cache::get(QueueControl::STOP_FLAG_KEY, false)) {
            return;
        }

        $interval = (int) SyncSetting::get('interval_minutes', 15);
        $lastRunRaw = SyncSetting::get('last_run_at');
        $lastRun = $lastRunRaw ? Carbon::parse($lastRunRaw) : null;

        if ($lastRun && $lastRun->diffInMinutes(now()) < $interval) {
            return;
        }

        SyncNewProductsJob::dispatch();
        SyncStockPriceJob::dispatch();
        PullBayiOrdersJob::dispatch();

        SyncSetting::put('last_run_at', now()->toDateTimeString());
    }
}
