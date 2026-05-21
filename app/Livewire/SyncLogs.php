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

    public ?int $expandedJobId = null;

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

    public function resetFilters(): void
    {
        $this->reset(['typeFilter', 'statusFilter', 'dateFrom', 'dateTo']);
        $this->resetPage();
    }

    public function toggleExpand(int $jobId): void
    {
        $this->expandedJobId = $this->expandedJobId === $jobId ? null : $jobId;
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

        $expandedLogs = $this->expandedJobId
            ? SyncLog::where('job_id', $this->expandedJobId)->orderBy('id')->limit(500)->get()
            : collect();

        $last24h = Carbon::now()->subDay();
        $errors24h = SyncLog::where('status', 'error')->where('created_at', '>=', $last24h)->count();
        $jobs24h = SyncJob::where('started_at', '>=', $last24h)->count();
        $failedJobs24h = SyncJob::where('status', 'failed')->where('started_at', '>=', $last24h)->count();

        return view('livewire.sync-logs', compact(
            'jobs', 'expandedLogs', 'errors24h', 'jobs24h', 'failedJobs24h'
        ));
    }
}
