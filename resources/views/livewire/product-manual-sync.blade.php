<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Manuel Ürün Aktarımı</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="bg-green-100 border border-green-300 text-green-800 px-4 py-2 rounded mb-4">
                    {{ session('status') }}
                </div>
            @endif

            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6 mb-4">
                <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Ana → Bayi Yeni Ürün Aktarımı</h3>
                <div class="flex flex-wrap items-end gap-3">
                    <div>
                        <label class="block text-xs text-gray-600 dark:text-gray-400">Başlangıç</label>
                        <input type="date" wire:model="pullSince" class="rounded-md border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-600 dark:text-gray-400">Bitiş</label>
                        <input type="date" wire:model="pullUntil" class="rounded-md border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200 text-sm">
                    </div>
                    <button wire:click="syncAll" class="px-4 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                        Yeni Ürünleri Çek
                    </button>
                    <button wire:click="updateAll" class="px-4 py-2 bg-indigo-600 text-white text-sm rounded hover:bg-indigo-700">
                        Tüm Stok/Fiyatı Güncelle
                    </button>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                <div class="flex flex-wrap gap-3 mb-4 items-center">
                    <input type="text" wire:model.live.debounce.400ms="search" placeholder="Barkod ara..."
                           class="rounded-md border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200 text-sm">
                    <select wire:model.live="statusFilter"
                            class="rounded-md border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200 text-sm">
                        <option value="">Tüm durumlar</option>
                        <option value="pending">Bekliyor</option>
                        <option value="synced">Eşleşti</option>
                        <option value="error">Hata</option>
                    </select>
                    <button wire:click="syncSelected"
                            @if(empty($selected)) disabled @endif
                            class="px-4 py-2 bg-green-600 text-white text-sm rounded hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed">
                        Seçilenleri Güncelle ({{ count($selected) }})
                    </button>
                </div>

                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900">
                        <tr>
                            <th class="px-2 py-2">
                                <input type="checkbox" wire:model.live="selectAll" title="Bu sayfadakileri seç">
                            </th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Barkod</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Ana ID</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Bayi ID</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Fiyat</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Stok</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Durum</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Son Sync</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">İşlem</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($mappings as $m)
                            <tr>
                                <td class="px-2 py-2"><input type="checkbox" wire:model.live="selected" value="{{ $m->id }}"></td>
                                <td class="px-4 py-2 text-sm text-gray-900 dark:text-gray-200 font-mono">{{ $m->barcode }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">{{ $m->ana_product_id }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">{{ $m->bayi_product_id }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">{{ $m->last_price }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">{{ $m->last_stock }}</td>
                                <td class="px-4 py-2 text-sm">
                                    @if ($m->status === 'synced')
                                        <span class="px-2 py-1 text-xs bg-green-100 text-green-800 rounded">Eşleşti</span>
                                    @elseif ($m->status === 'error')
                                        <button wire:click="showError({{ $m->id }})"
                                                class="px-2 py-1 text-xs bg-red-100 text-red-800 rounded hover:bg-red-200">
                                            Hata (Detay)
                                        </button>
                                    @else
                                        <span class="px-2 py-1 text-xs bg-yellow-100 text-yellow-800 rounded">Bekliyor</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                    {{ $m->last_synced_at?->format('Y-m-d H:i') ?? '-' }}
                                </td>
                                <td class="px-4 py-2 text-sm">
                                    <button wire:click="syncOne({{ $m->id }})"
                                            class="px-3 py-1 text-xs bg-blue-600 text-white rounded hover:bg-blue-700">
                                        Güncelle
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="px-4 py-6 text-center text-gray-500">Henüz ürün eşleştirmesi yok. Üstteki "Yeni Ürünleri Çek" butonunu kullanın.</td></tr>
                        @endforelse
                    </tbody>
                </table>

                <div class="mt-4">{{ $mappings->links() }}</div>
            </div>
        </div>

        @if ($this->errorMapping)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50" wire:click="closeError">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-2xl w-full mx-4 p-6" wire:click.stop>
                    <div class="flex justify-between items-start mb-4">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Hata Detayı — {{ $this->errorMapping->barcode }}</h3>
                        <button wire:click="closeError" class="text-gray-500 hover:text-gray-700 text-2xl leading-none">&times;</button>
                    </div>
                    <dl class="grid grid-cols-3 gap-2 text-sm mb-4">
                        <dt class="text-gray-500">Ana ID:</dt><dd class="col-span-2">{{ $this->errorMapping->ana_product_id ?: '-' }}</dd>
                        <dt class="text-gray-500">Bayi ID:</dt><dd class="col-span-2">{{ $this->errorMapping->bayi_product_id ?: '-' }}</dd>
                        <dt class="text-gray-500">Son Deneme:</dt><dd class="col-span-2">{{ $this->errorMapping->updated_at->format('Y-m-d H:i:s') }}</dd>
                    </dl>
                    <div class="bg-red-50 border border-red-200 rounded p-3 text-sm text-red-800 whitespace-pre-wrap font-mono break-all">
                        {{ $this->errorMapping->last_error }}
                    </div>
                    <div class="mt-4 flex justify-end gap-2">
                        <button wire:click="syncOne({{ $this->errorMapping->id }})" class="px-4 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                            Tekrar Dene
                        </button>
                        <button wire:click="closeError" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 text-sm rounded">
                            Kapat
                        </button>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
