<?php

namespace App\Livewire;

use App\Jobs\PullBayiOrdersJob;
use App\Jobs\SyncNewProductsJob;
use App\Jobs\SyncStockPriceJob;
use App\Livewire\QueueControl;
use App\Models\SyncSetting;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Sync Ayarları')]
#[Layout('layouts.app')]
class SyncSettings extends Component
{
    public int $interval_minutes = 15;
    public bool $otomatik_aktif = false;
    public bool $otomatik_urunler = true;
    public bool $otomatik_stok_fiyat = true;
    public bool $otomatik_siparis = true;
    public ?string $last_run_at = null;

    public function mount(): void
    {
        $this->interval_minutes = (int) SyncSetting::get('interval_minutes', 15);
        $this->otomatik_aktif = (bool) SyncSetting::get('otomatik_aktif', false);
        $this->otomatik_urunler = (bool) SyncSetting::get('otomatik_urunler', true);
        $this->otomatik_stok_fiyat = (bool) SyncSetting::get('otomatik_stok_fiyat', true);
        $this->otomatik_siparis = (bool) SyncSetting::get('otomatik_siparis', true);
        $this->last_run_at = SyncSetting::get('last_run_at') ?: null;
    }

    protected function rules(): array
    {
        return [
            'interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'otomatik_aktif' => ['boolean'],
            'otomatik_urunler' => ['boolean'],
            'otomatik_stok_fiyat' => ['boolean'],
            'otomatik_siparis' => ['boolean'],
        ];
    }

    public function save(): void
    {
        $this->validate();
        SyncSetting::put('interval_minutes', $this->interval_minutes);
        SyncSetting::put('otomatik_aktif', $this->otomatik_aktif ? '1' : '0');
        SyncSetting::put('otomatik_urunler', $this->otomatik_urunler ? '1' : '0');
        SyncSetting::put('otomatik_stok_fiyat', $this->otomatik_stok_fiyat ? '1' : '0');
        SyncSetting::put('otomatik_siparis', $this->otomatik_siparis ? '1' : '0');
        session()->flash('status', 'Sync ayarları kaydedildi.');
    }

    /**
     * Tek seferlik manuel tetik — üç sync job'ını queue'ya dispatch eder.
     * Otomatik scheduler'ı beklemeden anında çalışır.
     */
    public function runNow(string $what = 'all'): void
    {
        // Önceki "stop" flag'i kalmış olabilir — yeni sync başlatmadan önce temizle,
        // yoksa job hemen "stop signal var" görüp anında çıkar.
        Cache::forget(QueueControl::STOP_FLAG_KEY);

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
