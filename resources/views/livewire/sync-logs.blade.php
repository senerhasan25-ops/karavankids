<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Sync Logları</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            {{-- Last 24h summary cards --}}
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
                    <div class="text-xs uppercase text-gray-500">Son 24 Saat — Toplam İş</div>
                    <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100 mt-1">{{ $jobs24h }}</div>
                </div>
                <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
                    <div class="text-xs uppercase text-gray-500">Son 24 Saat — Başarısız İş</div>
                    <div class="text-2xl font-semibold {{ $failedJobs24h > 0 ? 'text-red-600' : 'text-gray-900 dark:text-gray-100' }} mt-1">
                        {{ $failedJobs24h }}
                    </div>
                </div>
                <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
                    <div class="text-xs uppercase text-gray-500">Son 24 Saat — Hata Log Satırı</div>
                    <div class="text-2xl font-semibold {{ $errors24h > 0 ? 'text-red-600' : 'text-gray-900 dark:text-gray-100' }} mt-1">
                        {{ $errors24h }}
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-4">
                    <select wire:model.live="typeFilter"
                            class="rounded-md border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200">
                        <option value="">Tüm Tipler</option>
                        <option value="product_create">Ürün Oluşturma</option>
                        <option value="stock_price_update">Stok/Fiyat Güncelleme</option>
                        <option value="order_pull">Sipariş Çekme</option>
                        <option value="order_retry">Sipariş Tekrar Deneme</option>
                    </select>
                    <select wire:model.live="statusFilter"
                            class="rounded-md border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200">
                        <option value="">Tüm Durumlar</option>
                        <option value="running">Çalışıyor</option>
                        <option value="completed">Tamamlandı</option>
                        <option value="failed">Başarısız</option>
                    </select>
                    <input type="date" wire:model.live="dateFrom"
                           class="rounded-md border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200">
                    <input type="date" wire:model.live="dateTo"
                           class="rounded-md border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200">
                    <button wire:click="resetFilters"
                            class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-gray-200 text-sm rounded hover:bg-gray-300">
                        Filtreleri Sıfırla
                    </button>
                </div>

                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Tip</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Durum</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Başlangıç</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Süre</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Toplam</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Başarılı</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Hata</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($jobs as $j)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-900/50">
                                <td class="px-4 py-2 text-sm text-gray-900 dark:text-gray-200">{{ $j->id }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">{{ $j->type }}</td>
                                <td class="px-4 py-2 text-sm">
                                    @if ($j->status === 'completed')
                                        <span class="px-2 py-1 text-xs bg-green-100 text-green-800 rounded">Tamamlandı</span>
                                    @elseif ($j->status === 'failed')
                                        <span class="px-2 py-1 text-xs bg-red-100 text-red-800 rounded">Başarısız</span>
                                    @else
                                        <span class="px-2 py-1 text-xs bg-yellow-100 text-yellow-800 rounded">Çalışıyor</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">{{ $j->started_at?->format('Y-m-d H:i:s') }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                    @if ($j->started_at && $j->finished_at)
                                        {{ $j->started_at->diffInSeconds($j->finished_at) }}s
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">{{ $j->total }}</td>
                                <td class="px-4 py-2 text-sm text-green-700">{{ $j->success_count }}</td>
                                <td class="px-4 py-2 text-sm text-red-700">{{ $j->error_count }}</td>
                                <td class="px-4 py-2 text-sm">
                                    <button wire:click="toggleExpand({{ $j->id }})"
                                            class="text-blue-600 dark:text-blue-400 text-xs hover:underline">
                                        {{ $expandedJobId === $j->id ? 'Gizle' : 'Detay' }}
                                    </button>
                                </td>
                            </tr>
                            @if ($expandedJobId === $j->id)
                                <tr class="bg-gray-50 dark:bg-gray-900/50">
                                    <td colspan="9" class="px-4 py-3">
                                        @if ($j->last_error)
                                            <div class="mb-3 p-2 bg-red-50 dark:bg-red-950/30 text-red-800 dark:text-red-300 text-xs rounded">
                                                <strong>İş hatası:</strong> {{ $j->last_error }}
                                            </div>
                                        @endif
                                        @if ($expandedLogs->isEmpty())
                                            <div class="text-xs text-gray-500 italic">Bu iş için log satırı bulunamadı.</div>
                                        @else
                                            <table class="min-w-full text-xs">
                                                <thead class="text-gray-500">
                                                    <tr>
                                                        <th class="text-left px-2 py-1">Zaman</th>
                                                        <th class="text-left px-2 py-1">Barkod / ID</th>
                                                        <th class="text-left px-2 py-1">Aksiyon</th>
                                                        <th class="text-left px-2 py-1">Yön</th>
                                                        <th class="text-left px-2 py-1">Durum</th>
                                                        <th class="text-left px-2 py-1">Mesaj</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach ($expandedLogs as $log)
                                                        <tr class="border-t border-gray-200 dark:border-gray-700">
                                                            <td class="px-2 py-1 text-gray-600 dark:text-gray-400 whitespace-nowrap">
                                                                {{ $log->created_at?->format('H:i:s') }}
                                                            </td>
                                                            <td class="px-2 py-1 font-mono">{{ $log->barcode ?? '-' }}</td>
                                                            <td class="px-2 py-1">{{ $log->action }}</td>
                                                            <td class="px-2 py-1 text-gray-600 dark:text-gray-400">{{ $log->direction }}</td>
                                                            <td class="px-2 py-1">
                                                                @if ($log->status === 'success')
                                                                    <span class="text-green-700">✓</span>
                                                                @elseif ($log->status === 'error')
                                                                    <span class="text-red-700">✗</span>
                                                                @else
                                                                    <span class="text-gray-500">·</span>
                                                                @endif
                                                            </td>
                                                            <td class="px-2 py-1 {{ $log->status === 'error' ? 'text-red-700 dark:text-red-400' : '' }}">
                                                                {{ \Illuminate\Support\Str::limit($log->message, 120) }}
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr><td colspan="9" class="px-4 py-6 text-center text-gray-500">Henüz log yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>

                <div class="mt-4">{{ $jobs->links() }}</div>
            </div>
        </div>
    </div>
</div>
