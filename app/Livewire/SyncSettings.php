<?php

namespace App\Livewire;

use App\Jobs\PullBayiOrdersJob;
use App\Jobs\SyncNewProductsJob;
use App\Jobs\SyncStockPriceJob;
use App\Models\SyncSetting;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Sync Ayarları')]
#[Layout('layouts.app')]
class SyncSettings extends Component
{
    public int $interval_minutes = 15;
    public bool $otomatik_aktif = false;
    public ?string $last_run_at = null;

    public function mount(): void
    {
        $this->interval_minutes = (int) SyncSetting::get('interval_minutes', 15);
        $this->otomatik_aktif = (bool) SyncSetting::get('otomatik_aktif', false);
        $this->last_run_at = SyncSetting::get('last_run_at') ?: null;
    }

    protected function rules(): array
    {
        return [
            'interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'otomatik_aktif' => ['boolean'],
        ];
    }

    public function save(): void
    {
        $this->validate();
        SyncSetting::put('interval_minutes', $this->interval_minutes);
        SyncSetting::put('otomatik_aktif', $this->otomatik_aktif ? '1' : '0');
        session()->flash('status', 'Sync ayarları kaydedildi.');
    }

    /**
     * Tek seferlik manuel tetik — üç sync job'ını queue'ya dispatch eder.
     * Otomatik scheduler'ı beklemeden anında çalışır.
     */
    public function runNow(string $what = 'all'): void
    {
        $dispatched = [];

        if ($what === 'all' || $what === 'products') {
            SyncNewProductsJob::dispatch();
            $dispatched[] = 'Ürün sync';
        }
        if ($what === 'all' || $what === 'stock') {
            SyncStockPriceJob::dispatch();
            $dispatched[] = 'Stok/Fiyat';
        }
        if ($what === 'all' || $what === 'orders') {
            PullBayiOrdersJob::dispatch();
            $dispatched[] = 'Sipariş aktarımı';
        }

        session()->flash('status', 'Kuyruğa alındı: ' . implode(', ', $dispatched) . '. Loglar sekmesinden ilerlemeyi takip edebilirsin.');
    }

    public function render()
    {
        return view('livewire.sync-settings');
    }
}
