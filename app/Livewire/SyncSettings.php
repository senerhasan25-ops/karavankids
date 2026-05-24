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

    public int $siparis_saat_aralik = 24;
    public int $siparis_durumu = 0;

    public function mount(): void
    {
        $this->interval_minutes = (int) SyncSetting::get('interval_minutes', 15);
        $this->otomatik_aktif = (bool) SyncSetting::get('otomatik_aktif', false);
        $this->otomatik_urunler = (bool) SyncSetting::get('otomatik_urunler', true);
        $this->otomatik_stok_fiyat = (bool) SyncSetting::get('otomatik_stok_fiyat', true);
        $this->otomatik_siparis = (bool) SyncSetting::get('otomatik_siparis', true);
        $this->last_run_at = SyncSetting::get('last_run_at') ?: null;
        $this->siparis_saat_aralik = (int) SyncSetting::get('siparis_saat_aralik', 24);
        $this->siparis_durumu = (int) SyncSetting::get('siparis_durumu', 0);
    }

    protected function rules(): array
    {
        return [
            'interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'otomatik_aktif' => ['boolean'],
            'otomatik_urunler' => ['boolean'],
            'otomatik_stok_fiyat' => ['boolean'],
            'otomatik_siparis' => ['boolean'],
            'siparis_saat_aralik' => ['required', 'integer', 'min:1', 'max:720'],
            'siparis_durumu' => ['required', 'integer', 'min:-1'],
        ];
    }

    public function setSaatAralik(int $saat): void
    {
        $this->siparis_saat_aralik = $saat;
    }

    /**
     * Ayarları kaydeder. İki davranış:
     *  - Master AÇIK → ayarlar saklanır, scheduler periyodik çalıştırır
     *  - Master KAPALI → işaretli sync türleri TEK SEFERLİK hemen kuyruğa alınır
     *    (eski "Şimdi Çalıştır" butonu bu mantığa gömüldü)
     */
    public function save(): void
    {
        $this->validate();
        SyncSetting::put('interval_minutes', $this->interval_minutes);
        SyncSetting::put('otomatik_aktif', $this->otomatik_aktif ? '1' : '0');
        SyncSetting::put('otomatik_urunler', $this->otomatik_urunler ? '1' : '0');
        SyncSetting::put('otomatik_stok_fiyat', $this->otomatik_stok_fiyat ? '1' : '0');
        SyncSetting::put('otomatik_siparis', $this->otomatik_siparis ? '1' : '0');
        SyncSetting::put('siparis_saat_aralik', $this->siparis_saat_aralik);
        SyncSetting::put('siparis_durumu', $this->siparis_durumu);

        if ($this->otomatik_aktif) {
            session()->flash('status', 'Ayarlar kaydedildi. Scheduler her ' . $this->interval_minutes . ' dk seçilenleri çalıştıracak.');
            return;
        }

        // Master KAPALI → işaretli sync'leri tek seferlik dispatch
        Cache::forget(QueueControl::STOP_FLAG_KEY); // önceki stop kalıntısı varsa temizle
        $dispatched = [];
        if ($this->otomatik_urunler) {
            SyncNewProductsJob::dispatch();
            $dispatched[] = '📦 Ürünler';
        }
        if ($this->otomatik_stok_fiyat) {
            SyncStockPriceJob::dispatch();
            $dispatched[] = '💰 Stok / Fiyat';
        }
        if ($this->otomatik_siparis) {
            PullBayiOrdersJob::dispatch();
            $dispatched[] = '🛒 Siparişler';
        }

        if (empty($dispatched)) {
            session()->flash('status', 'Ayarlar kaydedildi. Hiçbir sync türü seçili olmadığı için kuyruğa iş eklenmedi.');
        } else {
            session()->flash('status', 'Ayarlar kaydedildi + kuyruğa alındı: ' . implode(', ', $dispatched) . '. İlerleme için Loglar sekmesine bak.');
        }
    }

    public function render()
    {
        return view('livewire.sync-settings');
    }
}
