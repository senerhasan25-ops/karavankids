<div class="p-6 max-w-7xl mx-auto">
    <h1 class="text-2xl font-bold mb-4">Manuel Ürün Listele</h1>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
        Ana mağazadan stok koduna göre ürün ara. Tek değer girersen "içerir" (LIKE) araması,
        virgülle ayırırsan birden çok birebir arama yapar.
    </p>

    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4 mb-4">
        <div class="flex gap-2 items-end">
            <div class="flex-1">
                <label class="block text-sm font-medium mb-1">Stok Kodu</label>
                <input type="text" wire:model.live="query"
                       wire:keydown.enter="listele"
                       placeholder="örn: 302703  veya  302703, 302704, 302705"
                       class="w-full px-3 py-2 border rounded-md dark:bg-gray-900 dark:border-gray-700">
            </div>
            <button wire:click="listele" wire:loading.attr="disabled"
                    class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-md disabled:opacity-50">
                <span wire:loading.remove wire:target="listele">Listele</span>
                <span wire:loading wire:target="listele">Aranıyor…</span>
            </button>
        </div>

        @if($error)
            <div class="mt-3 px-4 py-2 bg-red-100 dark:bg-red-900/30 border border-red-300 dark:border-red-700 rounded text-sm text-red-800 dark:text-red-200">
                {{ $error }}
            </div>
        @endif

        @if($hasSearched && $resultCount > 0)
            <div class="mt-3 text-sm text-gray-600 dark:text-gray-400">
                {{ $resultCount }} ürün/varyasyon bulundu.
            </div>
        @endif
    </div>

    @if(! empty($products))
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
            <div class="px-4 py-3 border-b dark:border-gray-700 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <input type="checkbox" wire:model.live="selectAll" id="selAll"
                           class="rounded">
                    <label for="selAll" class="text-sm cursor-pointer">Hepsini Seç</label>
                    @if(count($selected) > 0)
                        <span class="ml-3 text-sm text-blue-600 dark:text-blue-400">{{ count($selected) }} satır seçili</span>
                    @endif
                </div>
                <button wire:click="aktarimaGec" wire:loading.attr="disabled"
                        @disabled(count($selected) === 0)
                        class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded-md disabled:opacity-50">
                    Aktarıma Geç →
                </button>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900 text-left">
                        <tr>
                            <th class="px-3 py-2 w-10"></th>
                            <th class="px-3 py-2">Ürün Kart ID</th>
                            <th class="px-3 py-2">Ürün ID</th>
                            <th class="px-3 py-2">Stok Kodu</th>
                            <th class="px-3 py-2">Ürün Adı</th>
                            <th class="px-3 py-2">Barkod</th>
                            <th class="px-3 py-2 text-right">Stok</th>
                            <th class="px-3 py-2 text-right">Satış</th>
                            <th class="px-3 py-2 text-right">İndirimli</th>
                            <th class="px-3 py-2">Aktif</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($products as $row)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                <td class="px-3 py-2">
                                    <input type="checkbox" wire:model.live="selected" value="{{ $row['variant_id'] }}" class="rounded">
                                </td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $row['urun_karti_id'] }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $row['variant_id'] }}</td>
                                <td class="px-3 py-2 font-mono">{{ $row['stok_kodu'] }}</td>
                                <td class="px-3 py-2 max-w-md truncate" title="{{ $row['urun_adi'] }}">{{ $row['urun_adi'] }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $row['barkod'] }}</td>
                                <td class="px-3 py-2 text-right">{{ $row['stok_adedi'] }}</td>
                                <td class="px-3 py-2 text-right">{{ number_format($row['satis_fiyati'], 2, ',', '.') }}</td>
                                <td class="px-3 py-2 text-right">
                                    @if($row['indirimli_fiyat'] > 0)
                                        {{ number_format($row['indirimli_fiyat'], 2, ',', '.') }}
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    @if($row['aktif'])
                                        <span class="px-2 py-0.5 text-xs rounded bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300">Aktif</span>
                                    @else
                                        <span class="px-2 py-0.5 text-xs rounded bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">Pasif</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
