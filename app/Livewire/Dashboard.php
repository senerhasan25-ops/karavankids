<?php

namespace App\Livewire;

use App\Models\OrderTransfer;
use App\Models\ProductMapping;
use App\Models\SyncJob;
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

        return view('livewire.dashboard', compact(
            'ordersToday', 'productsToday', 'days', 'maxBar',
            'recentFailed', 'lastRun', 'nextRun', 'autoEnabled',
            'intervalMinutes', 'recentJobs'
        ));
    }
}
