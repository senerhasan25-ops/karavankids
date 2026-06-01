<?php

namespace App\Livewire;

use App\Jobs\FullRemapProductsJob;
use App\Jobs\PullBayiOrdersJob;
use App\Jobs\SyncNewProductsJob;
use App\Jobs\SyncStockPriceJob;
use App\Models\SyncSetting;
use App\Services\Ticimax\OrderService;
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

    /** Stok/fiyat delta sync checkpoint (SyncStockPriceJob::LAST_RUN_KEY). */
    public ?string $last_stock_price_run_at = null;

    /** Yeni ürün delta sync checkpoint (SyncNewProductsJob::LAST_RUN_KEY). */
    public ?string $last_new_products_run_at = null;

    /** Ticimax'tan çekilen sipariş durumları: [['id' => 1, 'ad' => 'Yeni Sipariş'], ...] */
    public array $siparisDurumlari = [];

    /** Seçili durum ID'leri — boş = tümü */
    public array $seciliDurumlar = [];

    public ?string $durumYuklemHata = null;

    public function mount(): void
    {
        $this->interval_minutes = (int) SyncSetting::get('interval_minutes', 15);
        $this->otomatik_aktif = (bool) SyncSetting::get('otomatik_aktif', false);
        $this->otomatik_urunler = (bool) SyncSetting::get('otomatik_urunler', true);
        $this->otomatik_stok_fiyat = (bool) SyncSetting::get('otomatik_stok_fiyat', true);
        $this->otomatik_siparis = (bool) SyncSetting::get('otomatik_siparis', true);
        $this->last_run_at = SyncSetting::get('last_run_at') ?: null;
        $this->last_stock_price_run_at = SyncSetting::get(SyncStockPriceJob::LAST_RUN_KEY) ?: null;
        $this->last_new_products_run_at = SyncSetting::get(SyncNewProductsJob::LAST_RUN_KEY) ?: null;
        $this->siparis_saat_aralik = (int) SyncSetting::get('siparis_saat_aralik', 24);

        $seciliRaw = SyncSetting::get('secili_siparis_durumlari', '');
        $this->seciliDurumlar = ($seciliRaw && $seciliRaw !== '[]')
            ? json_decode($seciliRaw, true)
            : [];

        // DB'de kayıtlı liste varsa kullan; yoksa Ticimax'tan bir kez çek ve kaydet
        $listRaw = SyncSetting::get('siparis_durum_listesi', '');
        if ($listRaw && $listRaw !== '[]') {
            $this->siparisDurumlari = json_decode($listRaw, true);
        } else {
            $this->yukleSiparisDurumlari(); // ilk açılışta bir kez otomatik çek
        }
    }

    /**
     * Ticimax'tan sipariş durumlarını çek, DB'ye kaydet.
     * Sadece "Yenile" butonuna basılınca çağrılır.
     */
    public function yukleSiparisDurumlari(): void
    {
        try {
            $list = OrderService::for('bayi')->getOrderStatuses();
            if (! empty($list)) {
                SyncSetting::put('siparis_durum_listesi', json_encode($list));
                $this->siparisDurumlari = $list;
                $this->durumYuklemHata = null;
            } else {
                $this->durumYuklemHata = 'Ticimax sıfır kayıt döndürdü. WSDL metodunu kontrol edin.';
            }
        } catch (\Throwable $e) {
            $this->durumYuklemHata = $e->getMessage();
        }
    }

    public function toggleDurum(int $id): void
    {
        if (in_array($id, $this->seciliDurumlar, true)) {
            $this->seciliDurumlar = array_values(
                array_filter($this->seciliDurumlar, fn ($d) => $d !== $id)
            );
        } else {
            $this->seciliDurumlar[] = $id;
        }
    }

    public function tumunuSec(): void
    {
        $this->seciliDurumlar = [];
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
        ];
    }

    /**
     * Ayarları kaydeder. İki davranış:
     *  - Master AÇIK → ayarlar saklanır, scheduler periyodik çalıştırır
     *  - Master KAPALI → işaretli sync türleri TEK SEFERLİK hemen kuyruğa alınır
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
        SyncSetting::put('secili_siparis_durumlari', json_encode(array_values($this->seciliDurumlar)));

        if ($this->otomatik_aktif) {
            session()->flash('status', 'Ayarlar kaydedildi. Scheduler her '.$this->interval_minutes.' dk seçilenleri çalıştıracak.');

            return;
        }

        // Master KAPALI → işaretli sync'leri tek seferlik dispatch
        Cache::forget(QueueControl::STOP_FLAG_KEY);
        $dispatched = [];
        if ($this->otomatik_urunler) {
            if (SyncNewProductsJob::dispatchUnique()) {
                $dispatched[] = '📦 Ürünler';
            } else {
                $dispatched[] = '📦 Ürünler (zaten çalışıyor/kuyrukta — atlandı)';
            }
        }
        if ($this->otomatik_stok_fiyat) {
            if (SyncStockPriceJob::dispatchUnique()) {
                $dispatched[] = '💰 Stok / Fiyat';
            } else {
                $dispatched[] = '💰 Stok / Fiyat (zaten çalışıyor/kuyrukta — atlandı)';
            }
        }
        if ($this->otomatik_siparis) {
            if (PullBayiOrdersJob::dispatchUnique()) {
                $dispatched[] = '🛒 Siparişler';
            } else {
                $dispatched[] = '🛒 Siparişler (zaten çalışıyor/kuyrukta — atlandı)';
            }
        }

        if (empty($dispatched)) {
            session()->flash('status', 'Ayarlar kaydedildi. Hiçbir sync türü seçili olmadığı için kuyruğa iş eklenmedi.');
        } else {
            session()->flash('status', 'Ayarlar kaydedildi + kuyruğa alındı: '.implode(', ', $dispatched).'. İlerleme için Loglar sekmesine bak.');
        }
    }

    /**
     * Stok/fiyat delta checkpoint'ini sıfırla.
     * Bir sonraki job çalışmasında son 24 saate bakılır (ilk-çalışma davranışı).
     * Tüm ürünleri yeniden taramak istediğinde kullanılır.
     */
    public function sifirlaStokFiyatCheckpoint(): void
    {
        SyncSetting::put(SyncStockPriceJob::LAST_RUN_KEY, '');
        $this->last_stock_price_run_at = null;
        session()->flash('status', 'Stok/fiyat checkpoint sıfırlandı — bir sonraki çalışmada son 24 saatteki tüm değişiklikler taranacak.');
    }

    /**
     * Yeni ürün delta checkpoint'ini sıfırla.
     * Bir sonraki job çalışmasında son 7 güne bakılır (ilk-çalışma davranışı).
     */
    public function sifirlaYeniUrunCheckpoint(): void
    {
        SyncSetting::put(SyncNewProductsJob::LAST_RUN_KEY, '');
        $this->last_new_products_run_at = null;
        session()->flash('status', 'Yeni ürün checkpoint sıfırlandı — bir sonraki çalışmada son 7 gün taranacak.');
    }

    /**
     * BİR KERELİK tam eşleştirme — ana+bayi tüm ürünleri çekip product_mappings'i
     * eksiksiz doldurur (ürün oluşturmaz/güncellemez). Arka plan job'una devreder.
     * Tamamlanınca delta checkpoint'leri now()'a çekilir → sonra sadece yeni/değişen.
     */
    public function haritayiYenidenKur(): void
    {
        Cache::forget(QueueControl::STOP_FLAG_KEY);
        FullRemapProductsJob::dispatch();
        session()->flash('status', '🗺️ Tam eşleştirme kuyruğa alındı — ana + bayi tüm ürünler taranıp harita kurulacak '
            .'(ürün OLUŞTURULMAZ, sadece eşleştirilir). İlerlemeyi Loglar / Kuyruk durumundan izleyebilirsin.');
    }

    public function render()
    {
        return view('livewire.sync-settings');
    }
}
