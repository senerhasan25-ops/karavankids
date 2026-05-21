<?php

namespace App\Livewire;

use App\Jobs\SyncNewProductsJob;
use App\Jobs\SyncStockPriceJob;
use App\Models\ProductMapping;
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

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function syncAll(): void
    {
        SyncNewProductsJob::dispatch();
        session()->flash('status', 'Yeni ürünleri çekme işi kuyruğa alındı.');
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
        session()->flash('status', "{$count} ürün için güncelleme işleri kuyruğa alındı.");
    }

    public function render()
    {
        $mappings = ProductMapping::query()
            ->when($this->search, fn ($q) => $q->where('barcode', 'like', "%{$this->search}%"))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->orderByDesc('updated_at')
            ->paginate(25);

        return view('livewire.product-manual-sync', compact('mappings'));
    }
}
