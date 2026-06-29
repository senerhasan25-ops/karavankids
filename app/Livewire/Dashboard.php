<?php

namespace App\Livewire;

use App\Jobs\SyncNewProductsJob;
use App\Jobs\SyncStockPriceJob;
use App\Models\OrderTransfer;
use App\Models\ProductMapping;
use App\Models\SyncJob;
use App\Models\SyncLog;
use App\Models\SyncSetting;
use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Dashboard')]
#[Layout('layouts.app')]
class Dashboard extends Component
{
    public function render()
    {
        $today = Carbon::today();
        $weekAgo = Carbon::today()->subDays(6); // inclusive 7-day window

        // Today's counts
        $ordersToday = OrderTransfer::whereDate('transferred_at', $today)
            ->where('status', 'transferred')->count();
        $productsToday = ProductMapping::whereDate('last_synced_at', $today)->count();

        // Last 7-day buckets (oldest → newest)
        $days = collect();
        for ($i = 6; $i >= 0; $i--) {
            $d = Carbon::today()->subDays($i);
            $days->push([
                'label' => $d->format('d.m'),
                'date' => $d->format('Y-m-d'),
                'orders' => OrderTransfer::whereDate('transferred_at', $d)
                    ->where('status', 'transferred')->count(),
                'products' => ProductMapping::whereDate('last_synced_at', $d)->count(),
            ]);
        }

        $maxBar = max($days->max('orders'), $days->max('products'), 1);

        // Recent failed transfers (top 5)
        $recentFailed = OrderTransfer::where('status', 'failed')
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get();

        // Scheduler timing
        $lastRunRaw = SyncSetting::get('last_run_at');
        $lastRun = $lastRunRaw ? Carbon::parse($lastRunRaw) : null;
        $intervalMinutes = (int) (SyncSetting::get('interval_minutes', 15));
        $autoEnabled = filter_var(SyncSetting::get('otomatik_aktif', '0'), FILTER_VALIDATE_BOOL);
        $nextRun = ($lastRun && $autoEnabled) ? $lastRun->copy()->addMinutes($intervalMinutes) : null;

        // Last completed jobs (top 5)
        $recentJobs = SyncJob::orderByDesc('id')->limit(5)->get();

        // ── EŞLEŞTİRME SAĞLIK RAPORU ──────────────────────────────────────────
        // product_mappings: bir satır = bir varyasyon. Eşleşme yalnızca tedarikçi
        // kodu ile yapılır; bu metrikler "sistem ne kadarını doğru eşleştirdi" sorusunu
        // yanıtlar.
        $mapTotal = ProductMapping::count();
        $mapSynced = ProductMapping::where('status', 'synced')->whereNotNull('bayi_product_id')->count();
        $mapPending = ProductMapping::where('status', 'pending')->count();
        $mapError = ProductMapping::where('status', 'error')->count();
        $mapNoBayi = ProductMapping::whereNull('bayi_product_id')->count(); // ana var, bayide yok
        $mapNoTed = ProductMapping::where(function ($q) {
            $q->whereNull('tedarikci_kodu')->orWhere('tedarikci_kodu', '');
        })->count(); // tedarikçi kodu yok → eşleşemez
        $coverage = $mapTotal > 0 ? (int) round($mapSynced / $mapTotal * 100) : 0;

        // Sync zaman damgaları (delta checkpoint'leri)
        $lastNewProducts = SyncSetting::get(SyncNewProductsJob::LAST_RUN_KEY) ?: null;
        $lastStockPrice = SyncSetting::get(SyncStockPriceJob::LAST_RUN_KEY) ?: null;

        // Son 24 saat hata log satırı
        $errors24h = SyncLog::where('status', 'error')
            ->where('created_at', '>=', Carbon::now()->subDay())
            ->count();

        return view('livewire.dashboard', compact(
            'ordersToday', 'productsToday', 'days', 'maxBar',
            'recentFailed', 'lastRun', 'nextRun', 'autoEnabled',
            'intervalMinutes', 'recentJobs',
            'mapTotal', 'mapSynced', 'mapPending', 'mapError', 'mapNoBayi', 'mapNoTed', 'coverage',
            'lastNewProducts', 'lastStockPrice', 'errors24h'
        ));
    }
}
