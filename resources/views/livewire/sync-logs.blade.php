<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Sync Logları</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            {{-- Last 24h summary cards --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
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
                <div class="grid grid-cols-1 sm:grid-cols-5 gap-4 mb-4">
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
                                                        <th class="text-left px-2 py-1">Tarih · Saat</th>
                                                        <th class="text-left px-2 py-1">Durum</th>
                                                        <th class="text-left px-2 py-1">Ürün / Sipariş</th>
                                                        <th class="text-left px-2 py-1">Detay</th>
                                                        <th class="text-left px-2 py-1">Mesaj</th>
                                                        <th class="text-left px-2 py-1"></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach ($expandedLogs as $log)
                                                        <tr class="border-t border-gray-200 dark:border-gray-700 align-top">
                                                            <td class="px-2 py-1 text-gray-600 dark:text-gray-400 whitespace-nowrap">
                                                                {{ $log->created_at?->format('Y-m-d') }}<br>
                                                                <span class="font-mono">{{ $log->created_at?->format('H:i:s') }}</span>
                                                            </td>
                                                            <td class="px-2 py-1 whitespace-nowrap">
                                                                @if ($log->status === 'success')
                                                                    <span class="px-2 py-0.5 bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300 rounded text-[10px]">✓ Aktarıldı</span>
                                                                @elseif ($log->status === 'error')
                                                                    <span class="px-2 py-0.5 bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300 rounded text-[10px]">✗ Aktarılmadı</span>
                                                                @elseif ($log->status === 'warning')
                                                                    <span class="px-2 py-0.5 bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300 rounded text-[10px]">⚠ Atlandı</span>
                                                                @else
                                                                    <span class="text-gray-500">·</span>
                                                                @endif
                                                                <div class="text-[10px] text-gray-400 mt-1">
                                                                    {{ $log->direction === 'ana_to_bayi' ? '→ Bayi' : '← Bayi' }}
                                                                </div>
                                                            </td>
                                                            <td class="px-2 py-1">
                                                                <div class="font-medium text-gray-800 dark:text-gray-200">{{ $log->urun_adi ?: '—' }}</div>
                                                                <div class="text-[10px] text-gray-500 dark:text-gray-400 mt-0.5 space-y-0.5">
                                                                    @if ($log->stok_kodu)<div>StokKodu: <span class="font-mono">{{ $log->stok_kodu }}</span></div>@endif
                                                                    @if ($log->barcode)<div>Barkod: <span class="font-mono">{{ $log->barcode }}</span></div>@endif
                                                                </div>
                                                            </td>
                                                            <td class="px-2 py-1 text-[10px] text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                                                @if ($log->ana_id)<div>Ana ID: {{ $log->ana_id }}</div>@endif
                                                                @if ($log->bayi_id)<div>Bayi ID: {{ $log->bayi_id }}</div>@endif
                                                                <div class="text-gray-400">{{ $log->action }}</div>
                                                            </td>
                                                            <td class="px-2 py-1 {{ $log->status === 'error' ? 'text-red-700 dark:text-red-400' : 'text-gray-700 dark:text-gray-300' }}">
                                                                {{ \Illuminate\Support\Str::limit($log->message, 140) }}
                                                            </td>
                                                            <td class="px-2 py-1 whitespace-nowrap">
                                                                @if ($log->raw_request || $log->raw_response)
                                                                    <button wire:click="showLogDetail({{ $log->id }})"
                                                                            class="px-2 py-1 bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200 rounded text-[10px] hover:bg-amber-200">
                                                                        🔍 SOAP Detay
                                                                    </button>
                                                                @endif
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

    {{-- SOAP Detay Modal --}}
    @if ($detailLog)
        <div class="fixed inset-0 z-50 overflow-y-auto" wire:click.self="closeLogDetail">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-black/60" wire:click="closeLogDetail"></div>
                <div class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-5xl w-full max-h-[90vh] overflow-hidden flex flex-col">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">SOAP Çağrı Detayı</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                Log #{{ $detailLog->id }} · {{ $detailLog->created_at?->format('Y-m-d H:i:s') }} ·
                                {{ $detailLog->urun_adi ?: ($detailLog->stok_kodu ?: $detailLog->barcode ?: '—') }}
                            </p>
                        </div>
                        <button wire:click="closeLogDetail" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 text-2xl leading-none">×</button>
                    </div>

                    <div class="p-6 overflow-y-auto flex-1 space-y-4 text-xs">
                        @if ($detailLog->message)
                            <div class="p-3 bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200 rounded">
                                <strong>Hata mesajı:</strong> {{ $detailLog->message }}
                            </div>
                        @endif

                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <h4 class="font-semibold text-gray-700 dark:text-gray-300">📤 Ticimax'a Gönderilen (SOAP Request)</h4>
                                <button onclick="navigator.clipboard.writeText(this.parentElement.nextElementSibling.innerText); this.innerText='Kopyalandı ✓'; setTimeout(()=>this.innerText='Kopyala',1500)"
                                        class="text-[10px] px-2 py-0.5 bg-gray-200 dark:bg-gray-700 rounded">Kopyala</button>
                            </div>
                            <pre class="bg-gray-900 text-green-300 p-3 rounded overflow-x-auto max-h-72 text-[10px] leading-snug font-mono whitespace-pre-wrap">{{ $detailLog->raw_request ?: '(boş)' }}</pre>
                        </div>

                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <h4 class="font-semibold text-gray-700 dark:text-gray-300">📥 Ticimax'tan Dönen (SOAP Response)</h4>
                                <button onclick="navigator.clipboard.writeText(this.parentElement.nextElementSibling.innerText); this.innerText='Kopyalandı ✓'; setTimeout(()=>this.innerText='Kopyala',1500)"
                                        class="text-[10px] px-2 py-0.5 bg-gray-200 dark:bg-gray-700 rounded">Kopyala</button>
                            </div>
                            <pre class="bg-gray-900 text-blue-300 p-3 rounded overflow-x-auto max-h-72 text-[10px] leading-snug font-mono whitespace-pre-wrap">{{ $detailLog->raw_response ?: '(boş)' }}</pre>
                        </div>
                    </div>

                    <div class="px-6 py-3 border-t border-gray-200 dark:border-gray-700 flex justify-end">
                        <button wire:click="closeLogDetail" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-gray-200 text-sm rounded">Kapat</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
