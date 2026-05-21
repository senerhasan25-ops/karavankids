<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Sipariş Aktarımları</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="bg-green-100 border border-green-300 text-green-800 px-4 py-2 rounded mb-4">
                    {{ session('status') }}
                </div>
            @endif

            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                {{-- Filters --}}
                <div class="grid grid-cols-1 sm:grid-cols-5 gap-3 mb-4">
                    <select wire:model.live="statusFilter"
                            class="rounded-md border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200">
                        <option value="">Tüm durumlar</option>
                        <option value="pending">Bekliyor</option>
                        <option value="transferred">Aktarıldı</option>
                        <option value="failed">Başarısız</option>
                    </select>
                    <input type="text" wire:model.live.debounce.400ms="search" placeholder="Sipariş ID ara..."
                           class="rounded-md border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200">
                    <input type="date" wire:model.live="dateFrom"
                           class="rounded-md border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200">
                    <input type="date" wire:model.live="dateTo"
                           class="rounded-md border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200">
                    <button wire:click="resetFilters"
                            class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-gray-200 text-sm rounded hover:bg-gray-300">
                        Filtreleri Sıfırla
                    </button>
                </div>

                <div class="flex flex-wrap gap-3 mb-4">
                    <button wire:click="pullNow" class="px-4 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                        Şimdi Bayi Siparişlerini Çek
                    </button>
                    <button wire:click="retryAllFailed" class="px-4 py-2 bg-amber-600 text-white text-sm rounded hover:bg-amber-700">
                        Başarısızları Tekrar Dene
                    </button>
                </div>

                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Bayi Sipariş ID</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Ana Sipariş ID</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Durum</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Deneme</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Oluşturuldu</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Aktarım</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Hata</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">İşlem</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($transfers as $t)
                            @php($missing = \App\Livewire\OrderTransfers::parseMissingBarcodes($t->last_error))
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-900/50">
                                <td class="px-4 py-2 text-sm">
                                    <button wire:click="showDetail({{ $t->id }})" class="text-blue-600 dark:text-blue-400 hover:underline">
                                        {{ $t->bayi_order_id }}
                                    </button>
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">{{ $t->ana_order_id ?? '-' }}</td>
                                <td class="px-4 py-2 text-sm">
                                    @if ($t->status === 'transferred')
                                        <span class="px-2 py-1 text-xs bg-green-100 text-green-800 rounded">Aktarıldı</span>
                                    @elseif ($t->status === 'failed')
                                        <span class="px-2 py-1 text-xs bg-red-100 text-red-800 rounded">Başarısız</span>
                                        @if ($missing)
                                            <span class="px-2 py-1 text-xs bg-orange-100 text-orange-800 rounded ml-1">Eksik Ürün</span>
                                        @endif
                                    @else
                                        <span class="px-2 py-1 text-xs bg-yellow-100 text-yellow-800 rounded">Bekliyor</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">{{ $t->retry_count }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">{{ $t->created_at?->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">{{ $t->transferred_at?->format('Y-m-d H:i') ?? '-' }}</td>
                                <td class="px-4 py-2 text-sm text-red-600 dark:text-red-400 max-w-md">
                                    @if ($missing)
                                        <div class="text-orange-700 dark:text-orange-300">
                                            Eksik barkod{{ count($missing) > 1 ? 'lar' : '' }}: {{ implode(', ', $missing) }}
                                            <br>
                                            <a href="{{ route('urunler') }}?barcode={{ urlencode($missing[0]) }}"
                                               class="text-xs underline hover:text-orange-900">→ Önce Ürünü Aktar</a>
                                        </div>
                                    @else
                                        {{ \Illuminate\Support\Str::limit($t->last_error, 80) }}
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-sm">
                                    @if ($t->status === 'failed')
                                        <button wire:click="retry({{ $t->id }})"
                                                class="px-3 py-1 text-xs bg-amber-600 text-white rounded hover:bg-amber-700">
                                            Tekrar Dene
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-4 py-6 text-center text-gray-500">Henüz sipariş aktarımı yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>

                <div class="mt-4">{{ $transfers->links() }}</div>
            </div>
        </div>
    </div>

    {{-- Detail modal --}}
    @if ($detail)
        <div class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" wire:click="closeDetail">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] overflow-y-auto"
                 wire:click.stop>
                <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex justify-between items-start">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                            Sipariş Aktarım Detayı
                        </h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                            Bayi: {{ $detail->bayi_order_id }}
                            @if ($detail->ana_order_id) · Ana: {{ $detail->ana_order_id }} @endif
                        </p>
                    </div>
                    <button wire:click="closeDetail" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div class="p-6 space-y-4 text-sm">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <div class="text-xs uppercase text-gray-500">Durum</div>
                            <div class="text-gray-900 dark:text-gray-200">{{ $detail->status }}</div>
                        </div>
                        <div>
                            <div class="text-xs uppercase text-gray-500">Deneme Sayısı</div>
                            <div class="text-gray-900 dark:text-gray-200">{{ $detail->retry_count }}</div>
                        </div>
                        <div>
                            <div class="text-xs uppercase text-gray-500">Oluşturuldu</div>
                            <div class="text-gray-900 dark:text-gray-200">{{ $detail->created_at }}</div>
                        </div>
                        <div>
                            <div class="text-xs uppercase text-gray-500">Aktarıldı</div>
                            <div class="text-gray-900 dark:text-gray-200">{{ $detail->transferred_at ?? '-' }}</div>
                        </div>
                    </div>

                    @if ($detail->last_error)
                        <div>
                            <div class="text-xs uppercase text-gray-500 mb-1">Son Hata</div>
                            <pre class="bg-red-50 dark:bg-red-950/30 text-red-800 dark:text-red-300 p-3 rounded text-xs whitespace-pre-wrap break-all">{{ $detail->last_error }}</pre>
                        </div>
                    @endif

                    @php($payload = $detail->payload_snapshot ?? [])
                    @if (! empty($payload['Urunler']))
                        <div>
                            <div class="text-xs uppercase text-gray-500 mb-1">Ürün Satırları ({{ count($payload['Urunler']) }})</div>
                            <table class="min-w-full text-xs border border-gray-200 dark:border-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-900">
                                    <tr>
                                        <th class="px-2 py-1 text-left">Barkod</th>
                                        <th class="px-2 py-1 text-left">Ürün</th>
                                        <th class="px-2 py-1 text-right">Adet</th>
                                        <th class="px-2 py-1 text-right">Birim Fiyat</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($payload['Urunler'] as $line)
                                        <tr class="border-t border-gray-200 dark:border-gray-700">
                                            <td class="px-2 py-1 font-mono">{{ $line['Barkod'] ?? '-' }}</td>
                                            <td class="px-2 py-1">{{ $line['UrunAdi'] ?? '-' }}</td>
                                            <td class="px-2 py-1 text-right">{{ $line['Adet'] ?? 0 }}</td>
                                            <td class="px-2 py-1 text-right">{{ number_format((float) ($line['BirimFiyat'] ?? 0), 2, ',', '.') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    <details class="border border-gray-200 dark:border-gray-700 rounded">
                        <summary class="px-3 py-2 cursor-pointer text-xs uppercase text-gray-500">Ham Payload (JSON)</summary>
                        <pre class="bg-gray-50 dark:bg-gray-900 p-3 text-xs overflow-x-auto">{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                    </details>
                </div>
            </div>
        </div>
    @endif
</div>
