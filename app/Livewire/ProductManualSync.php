<?php

namespace App\Livewire;

use App\Jobs\SyncNewProductsJob;
use App\Jobs\SyncStockPriceJob;
use App\Models\ProductMapping;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Manuel Ürün Aktarımı')]
#[Layout('layouts.app')]
class ProductManualSync extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    public array $selected = [];

    public bool $selectAll = false;

    public string $pullSince = '';

    public string $pullUntil = '';

    public ?int $errorModalId = null;

    public function mount(): void
    {
        $this->pullSince = Carbon::now()->subDays(7)->format('Y-m-d');
        $this->pullUntil = Carbon::now()->format('Y-m-d');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
        $this->selectAll = false;
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
        $this->selectAll = false;
    }

    public function updatedSelectAll(bool $value): void
    {
        if ($value) {
            $this->selected = $this->currentQuery()->pluck('id')->map(fn ($i) => (string) $i)->all();
        } else {
            $this->selected = [];
        }
    }

    public function syncAll(): void
    {
        $since = $this->pullSince ? Carbon::parse($this->pullSince)->startOfDay() : null;
        $until = $this->pullUntil ? Carbon::parse($this->pullUntil)->endOfDay() : null;
        if (SyncNewProductsJob::dispatchUnique($since, $until)) {
            session()->flash('status', "Yeni ürünleri çekme işi kuyruğa alındı ({$this->pullSince} → {$this->pullUntil}).");
        } else {
            session()->flash('status', 'Zaten çalışan/kuyrukta bir ürün işi var — yeni iş eklenmedi. Bitince tekrar deneyebilirsin.');
        }
    }

    public function updateAll(): void
    {
        SyncStockPriceJob::dispatch();
        session()->flash('status', 'Tüm eşleşmiş ürünler için stok/fiyat güncelleme işi kuyruğa alındı.');
    }

    public function syncOne(int $id): void
    {
        $mapping = ProductMapping::findOrFail($id);
        SyncStockPriceJob::dispatch($mapping->barcode);
        session()->flash('status', "{$mapping->barcode} için güncelleme işi kuyruğa alındı.");
    }

    public function syncSelected(): void
    {
        if (empty($this->selected)) {
            session()->flash('status', 'Hiçbir ürün seçilmedi.');

            return;
        }
        foreach (ProductMapping::whereIn('id', $this->selected)->cursor() as $m) {
            SyncStockPriceJob::dispatch($m->barcode);
        }
        $count = count($this->selected);
        $this->selected = [];
        $this->selectAll = false;
        session()->flash('status', "{$count} ürün için güncelleme işleri kuyruğa alındı.");
    }

    public function showError(int $id): void
    {
        $this->errorModalId = $id;
    }

    public function closeError(): void
    {
        $this->errorModalId = null;
    }

    public function getErrorMappingProperty(): ?ProductMapping
    {
        return $this->errorModalId ? ProductMapping::find($this->errorModalId) : null;
    }

    protected function currentQuery()
    {
        return ProductMapping::query()
            ->when($this->search, fn ($q) => $q->where('barcode', 'like', "%{$this->search}%"))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->orderByDesc('updated_at');
    }

    public function render()
    {
        return view('livewire.product-manual-sync', [
            'mappings' => $this->currentQuery()->paginate(25),
        ]);
    }
}
