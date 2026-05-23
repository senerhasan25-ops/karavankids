<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Sync Ayarları</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="bg-green-100 border border-green-300 text-green-800 px-4 py-2 rounded mb-4">
                    {{ session('status') }}
                </div>
            @endif

            <form wire:submit.prevent="save" class="bg-white dark:bg-gray-800 shadow rounded-lg p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Senkronizasyon Aralığı (dakika)</label>
                    <input type="number" wire:model="interval_minutes" min="1" max="1440"
                           class="mt-1 block w-full rounded-md border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200">
                    @error('interval_minutes')<span class="text-red-600 text-xs">{{ $message }}</span>@enderror
                </div>

                <div>
                    <label class="inline-flex items-center">
                        <input type="checkbox" wire:model="otomatik_aktif" class="rounded">
                        <span class="ms-2 text-sm text-gray-700 dark:text-gray-300">Otomatik senkronizasyonu aktif et</span>
                    </label>
                </div>

                <div class="text-sm text-gray-600 dark:text-gray-400">
                    Son çalışma: <strong>{{ $last_run_at ?: 'Henüz çalışmadı' }}</strong>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                        Kaydet
                    </button>
                </div>
            </form>

            {{-- Manuel tetik kartı: tek seferlik çalıştırma --}}
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6 mt-6 space-y-4">
                <div>
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Şimdi Çalıştır</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        Otomatik scheduler'ı beklemeden ilgili sync'i hemen kuyruğa alır.
                        İşlerin ilerlemesini <strong>Loglar</strong> sekmesinden görebilirsin.
                    </p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                    <button type="button" wire:click="runNow('all')"
                            class="px-3 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                        Hepsini Çalıştır
                    </button>
                    <button type="button" wire:click="runNow('products')"
                            class="px-3 py-2 bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-gray-200 text-sm rounded hover:bg-gray-300 dark:hover:bg-gray-600">
                        Sadece Ürünler
                    </button>
                    <button type="button" wire:click="runNow('stock')"
                            class="px-3 py-2 bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-gray-200 text-sm rounded hover:bg-gray-300 dark:hover:bg-gray-600">
                        Sadece Stok/Fiyat
                    </button>
                    <button type="button" wire:click="runNow('orders')"
                            class="px-3 py-2 bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-gray-200 text-sm rounded hover:bg-gray-300 dark:hover:bg-gray-600">
                        Sadece Siparişler
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
