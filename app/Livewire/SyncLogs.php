<?php

namespace App\Livewire;

use App\Models\SyncJob;
use App\Models\SyncLog;
use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Loglar')]
#[Layout('layouts.app')]
class SyncLogs extends Component
{
    use WithPagination;

    public string $typeFilter = '';

    public string $statusFilter = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    /* ---- Ürün-bazlı filtreler (sync_logs üzerinden) ---- */
    /** Barkod — partial match (LIKE %x%). */
    public string $barcodeFilter = '';

    /** Stok kodu — partial match. */
    public string $stokKoduFilter = '';

    /** Ürün ID — ana_id veya bayi_id ile eşleşir (tam eşleme). */
    public string $urunIdFilter = '';

    public ?int $expandedJobId = null;

    public ?int $detailLogId = null;

    public function updatingTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatingDateTo(): void
    {
        $this->resetPage();
    }

    public function updatingBarcodeFilter(): void
    {
        $this->resetPage();
    }

    public function updatingStokKoduFilter(): void
    {
        $this->resetPage();
    }

    public function updatingUrunIdFilter(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['typeFilter', 'statusFilter', 'dateFrom', 'dateTo', 'barcodeFilter', 'stokKoduFilter', 'urunIdFilter']);
        $this->resetPage();
    }

    /**
     * Ürün filtrelerinden en az biri dolu mu? render() içinde iki yerde kullanılıyor:
     *  1) Jobs query'sini eşleşen log'a sahip iş'lerle daraltmak için
     *  2) Açılmış iş'in log satırlarını filtrelemek için (eşleşmeyenler gizlensin)
     */
    protected function hasProductFilters(): bool
    {
        return $this->barcodeFilter !== '' || $this->stokKoduFilter !== '' || $this->urunIdFilter !== '';
    }

    /**
     * SyncLog query'sine ürün filtrelerini uygular. Hem jobs->whereHas('logs') hem
     * de expandedLogs için aynı koşullar — duplikasyondan kaçınmak için ortak.
     */
    protected function applyProductFilters($query): void
    {
        if ($this->barcodeFilter !== '') {
            $query->where('barcode', 'like', '%'.$this->barcodeFilter.'%');
        }
        if ($this->stokKoduFilter !== '') {
            $query->where('stok_kodu', 'like', '%'.$this->stokKoduFilter.'%');
        }
        if ($this->urunIdFilter !== '') {
            // ana_id VEYA bayi_id ile eşleşsin — kullanıcı hangi tarafın ID'sini bilmiyor olabilir
            $query->where(function ($q) {
                $q->where('ana_id', $this->urunIdFilter)
                    ->orWhere('bayi_id', $this->urunIdFilter);
            });
        }
    }

    public function toggleExpand(int $jobId): void
    {
        $this->expandedJobId = $this->expandedJobId === $jobId ? null : $jobId;
    }

    public function showLogDetail(int $logId): void
    {
        $this->detailLogId = $logId;
    }

    public function closeLogDetail(): void
    {
        $this->detailLogId = null;
    }

    /**
     * SyncJob.type → kullanıcı dostu Türkçe etiket.
     * Filtre dropdown'ı + tablo "Tip" sütunu burayı kullanır.
     */
    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'product_create' => '📦 Ürün Aktarımı',
            'product_remap' => '🗺️ Ürün Eşleştirme',
            'stock_price_update' => '💰 Stok / Fiyat Güncelleme',
            'order_pull' => '🛒 Sipariş Çekme',
            'order_pull_single' => '🛒 Tek Sipariş Aktarımı',
            'order_retry' => '🔁 Sipariş Tekrar Deneme',
            default => $type,
        };
    }

    /**
     * SyncLog.action → kullanıcı dostu Türkçe etiket.
     * Detay tablosundaki "Detay" sütununda action satırı için.
     */
    public static function actionLabel(string $action): string
    {
        return match ($action) {
            'create_product' => 'Ürün Oluşturma',
            'transfer_product' => 'Ürün Aktarımı',
            'update_stock_price' => 'Stok / Fiyat Güncelleme',
            'update_stock' => 'Stok Güncelleme',
            'update_price' => 'Fiyat Güncelleme',
            'transfer_order' => 'Sipariş Aktarımı',
            'transfer_order_manual' => 'Sipariş Aktarımı (Manuel)',
            'mark_order' => 'Sipariş İşaretleme',
            'remap' => 'Eşleştirme',
            default => $action,
        };
    }

    public function render()
    {
        $hasProductFilters = $this->hasProductFilters();

        $jobs = SyncJob::query()
            ->when($this->typeFilter, fn ($q) => $q->where('type', $this->typeFilter))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->dateFrom, fn ($q) => $q->whereDate('started_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('started_at', '<=', $this->dateTo))
            // Ürün filtresi: sadece eşleşen log satırı içeren iş'leri getir.
            // whereHas → log tablosunda eşleşme arar, JOIN'siz alt-sorgu (index dostu).
            ->when($hasProductFilters, fn ($q) => $q->whereHas('logs', fn ($l) => $this->applyProductFilters($l)))
            ->orderByDesc('id')
            ->paginate(20);

        // Detay panelinde de SADECE eşleşen satırlar görünsün — yoksa kullanıcı 500 satır
        // içinde aradığı ürünü gözle bulmaya çalışır. Ürün filtresi yokken tüm satırlar gelir.
        $expandedLogs = $this->expandedJobId
            ? SyncLog::where('job_id', $this->expandedJobId)
                ->when($hasProductFilters, fn ($q) => $this->applyProductFilters($q))
                ->orderBy('id')
                ->limit(500)
                ->get()
            : collect();

        $last24h = Carbon::now()->subDay();
        $errors24h = SyncLog::where('status', 'error')->where('created_at', '>=', $last24h)->count();
        $jobs24h = SyncJob::where('started_at', '>=', $last24h)->count();
        $failedJobs24h = SyncJob::where('status', 'failed')->where('started_at', '>=', $last24h)->count();

        $detailLog = $this->detailLogId ? SyncLog::find($this->detailLogId) : null;

        return view('livewire.sync-logs', compact(
            'jobs', 'expandedLogs', 'errors24h', 'jobs24h', 'failedJobs24h', 'detailLog'
        ));
    }
}
