<?php

namespace App\Livewire;

use App\Jobs\PullBayiOrdersJob;
use App\Jobs\RetryFailedOrderTransferJob;
use App\Models\OrderTransfer;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Sipariş Aktarımları')]
#[Layout('layouts.app')]
class OrderTransfers extends Component
{
    use WithPagination;

    public string $statusFilter = '';
    public string $search = '';
    public string $dateFrom = '';
    public string $dateTo = '';

    public ?int $detailId = null;

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingSearch(): void
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

    public function resetFilters(): void
    {
        $this->reset(['statusFilter', 'search', 'dateFrom', 'dateTo']);
        $this->resetPage();
    }

    public function pullNow(): void
    {
        PullBayiOrdersJob::dispatch();
        session()->flash('status', 'Bayi sipariş çekme işi kuyruğa alındı.');
    }

    public function retry(int $id): void
    {
        RetryFailedOrderTransferJob::dispatch($id);
        session()->flash('status', "#{$id} için tekrar deneme işi kuyruğa alındı.");
    }

    public function retryAllFailed(): void
    {
        RetryFailedOrderTransferJob::dispatch();
        session()->flash('status', 'Başarısız tüm aktarımlar için tekrar deneme işi kuyruğa alındı.');
    }

    public function showDetail(int $id): void
    {
        $this->detailId = $id;
    }

    public function closeDetail(): void
    {
        $this->detailId = null;
    }

    /**
     * If last_error matches "Ana eşleşmesi yok: barkod1,barkod2", return the barcodes.
     * Otherwise null.
     */
    public static function parseMissingBarcodes(?string $error): ?array
    {
        if (! $error) {
            return null;
        }
        if (preg_match('/Ana eşleşmesi yok:\s*(.+)$/u', $error, $m)) {
            return array_values(array_filter(array_map('trim', explode(',', $m[1]))));
        }
        return null;
    }

    public function render()
    {
        $transfers = OrderTransfer::query()
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search, fn ($q) => $q->where(function ($qq) {
                $qq->where('bayi_order_id', 'like', '%' . $this->search . '%')
                   ->orWhere('ana_order_id', 'like', '%' . $this->search . '%');
            }))
            ->when($this->dateFrom, fn ($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('created_at', '<=', $this->dateTo))
            ->orderByDesc('created_at')
            ->paginate(25);

        $detail = $this->detailId ? OrderTransfer::find($this->detailId) : null;

        return view('livewire.order-transfers', compact('transfers', 'detail'));
    }
}
