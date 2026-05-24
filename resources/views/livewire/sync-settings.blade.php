<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Otomatik Güncelleme</h2>
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
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        Sadece "Otomatik aktif" işaretliyken kullanılır.
                    </p>
                </div>

                <div>
                    <label class="inline-flex items-center">
                        <input type="checkbox" wire:model.live="otomatik_aktif" class="rounded">
                        <span class="ms-2 text-sm font-medium text-gray-700 dark:text-gray-300">Otomatik senkronizasyonu aktif et</span>
                    </label>
                </div>

                <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Çalıştırılacak sync türleri
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-3 leading-relaxed">
                        @if ($otomatik_aktif)
                            <strong>Otomatik aktif:</strong> seçilenler her {{ $interval_minutes }} dk scheduler tarafından çalıştırılır.
                        @else
                            <strong>Otomatik kapalı:</strong> "Kaydet" tıklandığında seçilenler <u>tek seferlik</u> çalışır.
                        @endif
                    </p>
                    <div class="space-y-2">
                        <label class="inline-flex items-center w-full">
                            <input type="checkbox" wire:model="otomatik_urunler" class="rounded">
                            <span class="ms-2 text-sm text-gray-700 dark:text-gray-300">
                                📦 Ürünler (yeni ürün açma + güncelleme)
                            </span>
                        </label>
                        <label class="inline-flex items-center w-full">
                            <input type="checkbox" wire:model="otomatik_stok_fiyat" class="rounded">
                            <span class="ms-2 text-sm text-gray-700 dark:text-gray-300">
                                💰 Stok / Fiyat (sadece varolan ürünlerin güncellenmesi)
                            </span>
                        </label>
                        <label class="inline-flex items-center w-full">
                            <input type="checkbox" wire:model="otomatik_siparis" class="rounded">
                            <span class="ms-2 text-sm text-gray-700 dark:text-gray-300">
                                🛒 Siparişler (hedef → kaynak aktarımı)
                            </span>
                        </label>
                    </div>
                </div>

                <!-- Sipariş Aktarım Ayarları -->
                <div class="border-t border-gray-200 dark:border-gray-700 pt-4 space-y-5">
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">
                        🛒 Otomatik Sipariş Aktarım Ayarları
                    </p>

                    <!-- Saat Aralığı — sadece el girişi -->
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">
                            Son kaç saatteki siparişler sorgulanacak?
                        </label>
                        <div class="flex items-center gap-2">
                            <input type="number" wire:model="siparis_saat_aralik"
                                min="1" max="720"
                                class="w-28 text-sm rounded border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-200 py-1.5 px-2">
                            <span class="text-sm text-gray-600 dark:text-gray-400">saat</span>
                        </div>
                        @error('siparis_saat_aralik')<span class="text-red-600 text-xs">{{ $message }}</span>@enderror
                    </div>

                    <!-- Sipariş Durumu Filtrelemesi -->
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <label class="text-xs font-medium text-gray-600 dark:text-gray-400">
                                Sipariş Durumu Filtresi
                            </label>
                            <div class="flex items-center gap-3">
                                <button type="button" wire:click="yukleSiparisDurumlari"
                                    class="text-xs text-blue-600 dark:text-blue-400 hover:underline flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                    Yenile
                                </button>
                                <button type="button" wire:click="tumunuSec"
                                    @class([
                                        'text-xs px-2 py-0.5 rounded border transition',
                                        'bg-indigo-600 text-white border-indigo-600' => empty($seciliDurumlar),
                                        'bg-white dark:bg-gray-700 text-gray-600 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:border-indigo-400' => !empty($seciliDurumlar),
                                    ])>
                                    Tümü
                                </button>
                            </div>
                        </div>

                        @if ($durumYuklemHata)
                            <div class="text-xs text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded p-2 mb-2">
                                Sipariş durumları yüklenemedi: {{ $durumYuklemHata }}
                                <br>Bağlantıyı kontrol edip "Yenile" butonuna tıklayın.
                            </div>
                        @endif

                        @if (!empty($siparisDurumlari))
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                @foreach ($siparisDurumlari as $durum)
                                    <label class="inline-flex items-center gap-2 cursor-pointer select-none">
                                        <input type="checkbox"
                                            wire:click="toggleDurum({{ $durum['id'] }})"
                                            @checked(in_array($durum['id'], $seciliDurumlar))
                                            class="rounded text-indigo-600">
                                        <span class="text-xs text-gray-700 dark:text-gray-300">
                                            <span class="font-mono text-gray-400 dark:text-gray-500">#{{ $durum['id'] }}</span>
                                            {{ $durum['ad'] }}
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            <p class="text-xs text-gray-400 dark:text-gray-500 mt-2">
                                @if (empty($seciliDurumlar))
                                    Hiçbiri seçili değil = tüm durumlar sorgulanır.
                                @else
                                    Seçili: {{ implode(', ', $seciliDurumlar) }} — sadece bu durumdaki siparişler aktarılır.
                                @endif
                            </p>
                        @elseif (!$durumYuklemHata)
                            <div wire:loading class="text-xs text-gray-400 dark:text-gray-500">Yükleniyor...</div>
                            <div wire:loading.remove class="text-xs text-gray-400 dark:text-gray-500">
                                Sipariş durumu bulunamadı. "Yenile" butonuna tıklayın.
                            </div>
                        @endif
                    </div>
                </div>

                <div class="text-sm text-gray-600 dark:text-gray-400 border-t border-gray-200 dark:border-gray-700 pt-4">
                    Son çalışma: <strong>{{ $last_run_at ?: 'Henüz çalışmadı' }}</strong>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                        Kaydet
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
