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
                <div class="border-t border-gray-200 dark:border-gray-700 pt-4 space-y-4">
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">
                        🛒 Otomatik Sipariş Aktarım Ayarları
                    </p>

                    <!-- Saat Aralığı -->
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-2">
                            Son kaç saatteki siparişler sorgulanacak?
                            <span class="ms-1 font-bold text-blue-600 dark:text-blue-400">{{ $siparis_saat_aralik }} saat</span>
                        </label>
                        <div class="flex flex-wrap gap-2">
                            @foreach ([1, 3, 6, 12, 24, 48, 72] as $saat)
                                <button type="button"
                                    wire:click="setSaatAralik({{ $saat }})"
                                    @class([
                                        'px-3 py-1.5 rounded text-sm font-medium border transition',
                                        'bg-blue-600 text-white border-blue-600' => $siparis_saat_aralik === $saat,
                                        'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:border-blue-400' => $siparis_saat_aralik !== $saat,
                                    ])>
                                    {{ $saat }} saat
                                </button>
                            @endforeach
                            <div class="flex items-center gap-1">
                                <input type="number" wire:model.live="siparis_saat_aralik"
                                    min="1" max="720"
                                    placeholder="özel"
                                    class="w-20 text-sm rounded border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-200 py-1.5 px-2">
                                <span class="text-xs text-gray-500 dark:text-gray-400">saat (özel)</span>
                            </div>
                        </div>
                        @error('siparis_saat_aralik')<span class="text-red-600 text-xs">{{ $message }}</span>@enderror
                    </div>

                    <!-- Sipariş Durumu Filtresi -->
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">
                            Sipariş Durumu Filtresi
                        </label>
                        <select wire:model="siparis_durumu"
                            class="block w-full text-sm rounded border-gray-300 dark:bg-gray-700 dark:border-gray-600 dark:text-gray-200">
                            <option value="0">Tümü (0)</option>
                            <option value="1">Yeni Sipariş (1)</option>
                            <option value="2">İşlemde / Onaylandı (2)</option>
                            <option value="3">Kargoya Verildi (3)</option>
                            <option value="4">Teslim Edildi (4)</option>
                            <option value="5">İptal Edildi (5)</option>
                            <option value="6">İade (6)</option>
                        </select>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                            "Tümü" seçilirse Ticimax tüm durumları döner. Sipariş durum numaraları mağazadan mağazaya farklılık gösterebilir.
                        </p>
                        @error('siparis_durumu')<span class="text-red-600 text-xs">{{ $message }}</span>@enderror
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
