<?php

namespace App\Livewire;

use App\Models\SyncJob;
use App\Models\SyncLog;
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

    public function updatingTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $jobs = SyncJob::query()
            ->when($this->typeFilter, fn ($q) => $q->where('type', $this->typeFilter))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->dateFrom, fn ($q) => $q->whereDate('started_at', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('started_at', '<=', $this->dateTo))
            ->orderByDesc('id')
            ->paginate(20);

        return view('livewire.sync-logs', compact('jobs'));
    }
}
