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

    public function updatingStatusFilter(): void
    {
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

    public function render()
    {
        $transfers = OrderTransfer::query()
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->orderByDesc('created_at')
            ->paginate(25);

        return view('livewire.order-transfers', compact('transfers'));
    }
}
