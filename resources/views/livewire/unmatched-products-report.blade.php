<div class="py-6">
    <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg">
            <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between gap-4">
                <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    🔗 Eşleşmeyen Ürünler Raporu
                </h1>
                <div class="flex items-center gap-4 text-sm">
                    <div class="text-gray-600 dark:text-gray-400">
                        <strong class="text-rose-600 dark:text-rose-400">{{ $totalDistinctSku }}</strong> stok kodu /
                        <strong class="text-amber-600 dark:text-amber-400">{{ $totalAffectedOrders }}</strong> sipariş etkilendi
                    </div>
                </div>
            </div>

            @if (session('status'))
                <div class="m-4 px-4 py-2 bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 text-sm rounded">
                    {{ session('status') }}
                </div>
            @endif

            <div class="p-4 border-b border-gray-200 dark:border-gray-700">
                <input type="text" wire:model.live.debounce.300ms="search"
                    placeholder="Stok kodu ara..."
                    class="block w-full sm:w-72 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm" />
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    Bu rapor, aktarım sırasında ana mağazada eşleşemeyen stok kodlarını gösterir. İlgili ürünü ana sitede oluşturup
                    aktif yaptıktan sonra <strong>Tekrar Dene</strong> ile etkilenen siparişleri tek tıkla yeniden aktarıma alabilirsin.
                </p>
            </div>

            @if (empty($groups))
                <div class="p-8 text-center text-gray-500 text-sm">
                    🎉 Eşleşmeyen ürün hatası bulunmuyor.
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-900 text-xs uppercase text-gray-500 dark:text-gray-400">
                            <tr>
                                <th class="px-3 py-2 text-left w-12"></th>
                                <th class="px-3 py-2 text-left">Stok Kodu</th>
                                <th class="px-3 py-2 text-right">Etkilenen Sipariş</th>
                                <th class="px-3 py-2 text-left">İlk Görüldü</th>
                                <th class="px-3 py-2 text-left">Son Görüldü</th>
                                <th class="px-3 py-2 text-right">İşlem</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach ($groups as $g)
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-900/50">
                                    <td class="px-3 py-2 text-center">
                                        <button wire:click="toggleExpand('{{ $g['stok_kodu'] }}')"
                                            class="text-gray-500 hover:text-gray-800 dark:hover:text-gray-200 text-xs">
                                            {{ $expandedStokKodu === $g['stok_kodu'] ? '▼' : '▶' }}
                                        </button>
                                    </td>
                                    <td class="px-3 py-2 font-mono text-xs text-gray-900 dark:text-gray-100">
                                        {{ $g['stok_kodu'] }}
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-200">
                                            {{ $g['count'] }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-xs text-gray-600 dark:text-gray-400">
                                        {{ $g['first_seen']?->format('d.m.Y H:i') ?? '—' }}
                                    </td>
                                    <td class="px-3 py-2 text-xs text-gray-600 dark:text-gray-400">
                                        {{ $g['last_seen']?->format('d.m.Y H:i') ?? '—' }}
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <button wire:click="retryStokKodu('{{ $g['stok_kodu'] }}')"
                                            wire:confirm="'{{ $g['stok_kodu'] }}' için {{ $g['count'] }} sipariş tekrar aktarım kuyruğuna alınacak. Bu ürünü ana sitede oluşturdun mu?"
                                            class="px-2 py-1 bg-emerald-600 hover:bg-emerald-700 text-white text-xs rounded">
                                            🔁 Tekrar Dene
                                        </button>
                                    </td>
                                </tr>
                                @if ($expandedStokKodu === $g['stok_kodu'])
                                    <tr>
                                        <td colspan="6" class="px-6 py-3 bg-gray-50 dark:bg-gray-900/50">
                                            <div class="text-xs text-gray-700 dark:text-gray-300 mb-2">Etkilenen siparişler:</div>
                                            <div class="flex flex-wrap gap-1.5">
                                                @foreach ($g['orders'] as $ord)
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded font-mono text-xs bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700">
                                                        Bayi #{{ $ord['bayi_order_id'] }}
                                                        <span class="ml-1 text-gray-400 dark:text-gray-500">{{ $ord['updated_at']?->format('d.m H:i') }}</span>
                                                    </span>
                                                @endforeach
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
