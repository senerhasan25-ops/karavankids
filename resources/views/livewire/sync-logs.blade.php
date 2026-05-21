<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Sync Logları</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
                    <select wire:model.live="typeFilter"
                            class="rounded-md border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200">
                        <option value="">Tüm Tipler</option>
                        <option value="product_create">Ürün Oluşturma</option>
                        <option value="stock_price_update">Stok/Fiyat Güncelleme</option>
                        <option value="order_pull">Sipariş Çekme</option>
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
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($jobs as $j)
                            <tr>
                                <td class="px-4 py-2 text-sm text-gray-900 dark:text-gray-200">{{ $j->id }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">{{ $j->type }}</td>
                                <td class="px-4 py-2 text-sm">{{ $j->status }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">{{ $j->started_at?->format('Y-m-d H:i:s') }}</td>
                                <td class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">
                                    @if ($j->started_at && $j->finished_at)
                                        {{ $j->started_at->diffInSeconds($j->finished_at) }}s
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">{{ $j->total }}</td>
                                <td class="px-4 py-2 text-sm text-green-700">{{ $j->success_count }}</td>
                                <td class="px-4 py-2 text-sm text-red-700">{{ $j->error_count }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-4 py-6 text-center text-gray-500">Henüz log yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>

                <div class="mt-4">{{ $jobs->links() }}</div>
            </div>
        </div>
    </div>
</div>
