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

            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                <div class="flex flex-wrap gap-3 mb-4">
                    <input type="text" wire:model.live.debounce.400ms="search" placeholder="Barkod ara..."
                           class="rounded-md border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200">
                    <select wire:model.live="statusFilter"
                            class="rounded-md border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200">
                        <option value="">Tüm durumlar</option>
                        <option value="pending">Bekliyor</option>
                        <option value="synced">Eşleşti</option>
                        <option value="error">Hata</option>
                    </select>
                    <button wire:click="syncAll" class="px-4 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                        Yeni Ürünleri Çek (Ana→Bayi)
                    </button>
                    <button wire:click="updateAll" class="px-4 py-2 bg-indigo-600 text-white text-sm rounded hover:bg-indigo-700">
                        Tüm Stok/Fiyatı Güncelle
                    </button>
                    <button wire:click="syncSelected" class="px-4 py-2 bg-green-600 text-white text-sm rounded hover:bg-green-700">
                        Seçilenleri Güncelle ({{ count($selected) }})
                    </button>
                </div>

                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900">
                        <tr>
                            <th class="px-2 py-2"></th>
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
                                <td class="px-4 py-2 text-sm text-gray-900 dark:text-gray-200">{{ $m->barcode }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">{{ $m->ana_product_id }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">{{ $m->bayi_product_id }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">{{ $m->last_price }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">{{ $m->last_stock }}</td>
                                <td class="px-4 py-2 text-sm">
                                    @if ($m->status === 'synced')
                                        <span class="px-2 py-1 text-xs bg-green-100 text-green-800 rounded">Eşleşti</span>
                                    @elseif ($m->status === 'error')
                                        <span class="px-2 py-1 text-xs bg-red-100 text-red-800 rounded" title="{{ $m->last_error }}">Hata</span>
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
                            <tr><td colspan="9" class="px-4 py-6 text-center text-gray-500">Henüz ürün eşleştirmesi yok. "Yeni Ürünleri Çek" ile başlatın.</td></tr>
                        @endforelse
                    </tbody>
                </table>

                <div class="mt-4">{{ $mappings->links() }}</div>
            </div>
        </div>
    </div>
</div>
