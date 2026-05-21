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
                <div class="flex flex-wrap gap-3 mb-4">
                    <select wire:model.live="statusFilter"
                            class="rounded-md border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200">
                        <option value="">Tüm durumlar</option>
                        <option value="pending">Bekliyor</option>
                        <option value="transferred">Aktarıldı</option>
                        <option value="failed">Başarısız</option>
                    </select>
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
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Aktarım Zamanı</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Hata</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">İşlem</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($transfers as $t)
                            <tr>
                                <td class="px-4 py-2 text-sm text-gray-900 dark:text-gray-200">{{ $t->bayi_order_id }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">{{ $t->ana_order_id ?? '-' }}</td>
                                <td class="px-4 py-2 text-sm">
                                    @if ($t->status === 'transferred')
                                        <span class="px-2 py-1 text-xs bg-green-100 text-green-800 rounded">Aktarıldı</span>
                                    @elseif ($t->status === 'failed')
                                        <span class="px-2 py-1 text-xs bg-red-100 text-red-800 rounded">Başarısız</span>
                                    @else
                                        <span class="px-2 py-1 text-xs bg-yellow-100 text-yellow-800 rounded">Bekliyor</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">{{ $t->retry_count }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">{{ $t->transferred_at?->format('Y-m-d H:i') ?? '-' }}</td>
                                <td class="px-4 py-2 text-sm text-red-600 dark:text-red-400">{{ \Illuminate\Support\Str::limit($t->last_error, 60) }}</td>
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
                            <tr><td colspan="7" class="px-4 py-6 text-center text-gray-500">Henüz sipariş aktarımı yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>

                <div class="mt-4">{{ $transfers->links() }}</div>
            </div>
        </div>
    </div>
</div>
