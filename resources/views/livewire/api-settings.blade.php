<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">API Ayarları</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="bg-green-100 border border-green-300 text-green-800 px-4 py-2 rounded">
                    {{ session('status') }}
                </div>
            @endif

            <div class="bg-yellow-50 dark:bg-yellow-900/30 border border-yellow-200 dark:border-yellow-800 p-4 rounded">
                <p class="text-sm text-yellow-800 dark:text-yellow-200">
                    Test ortamında çalışıyorsan, aşağıdaki butona basarak Ticimax test endpoint'lerini formda otomatik doldurabilirsin.
                </p>
                <button wire:click="loadTestDefaults" class="mt-2 px-3 py-1 bg-yellow-600 text-white text-xs rounded hover:bg-yellow-700">
                    Test URL'lerini Doldur
                </button>
            </div>

            <form wire:submit.prevent="save" class="space-y-6">
                @foreach (['ana' => 'Kaynak Mağaza (ürünler buradan okunur, siparişler buraya yazılır)', 'bayi' => 'Hedef Mağaza (ürünler buraya yazılır, siparişler buradan çekilir)'] as $store => $label)
                    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                        <h3 class="text-lg font-semibold mb-4 text-gray-800 dark:text-gray-200">{{ $label }}</h3>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Endpoint URL (base)</label>
                                <input type="url" wire:model="{{ $store }}_endpoint"
                                       placeholder="https://..."
                                       class="mt-1 block w-full rounded-md border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200 shadow-sm">
                                @error("{$store}_endpoint")<span class="text-red-600 text-xs">{{ $message }}</span>@enderror
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">WSDL Path — Ürün</label>
                                <input type="text" wire:model="{{ $store }}_wsdl_product"
                                       placeholder="/Servis/UrunServis.svc?wsdl"
                                       class="mt-1 block w-full rounded-md border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200 shadow-sm font-mono text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">WSDL Path — Sipariş</label>
                                <input type="text" wire:model="{{ $store }}_wsdl_order"
                                       placeholder="/Servis/SiparisServis.svc?wsdl"
                                       class="mt-1 block w-full rounded-md border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200 shadow-sm font-mono text-sm">
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Web Servis Yetki Kodu / Üye Kodu</label>
                                <input type="text" wire:model="{{ $store }}_username"
                                       class="mt-1 block w-full rounded-md border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200 shadow-sm font-mono text-sm">
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                    Yeni Ticimax B2B kurulumlarında bu tek alan yeterli (Web Servis Yetki Kodu).
                                    Eski Üye Kodu/Şifre çifti kullanan kurulumlar için ayrıca aşağıdaki şifre alanını doldur.
                                </p>
                                @error("{$store}_username")<span class="text-red-600 text-xs">{{ $message }}</span>@enderror
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Şifre <span class="text-xs text-gray-500">(opsiyonel — yeni kurulumlarda boş bırak)</span>
                                </label>
                                <input type="password" wire:model="{{ $store }}_password"
                                       placeholder="Yeni Ticimax'ta boş bırakın"
                                       class="mt-1 block w-full rounded-md border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200 shadow-sm">
                                @error("{$store}_password")<span class="text-red-600 text-xs">{{ $message }}</span>@enderror
                            </div>
                            <div class="md:col-span-2 flex items-center gap-4">
                                <label class="inline-flex items-center">
                                    <input type="checkbox" wire:model="{{ $store }}_active" class="rounded">
                                    <span class="ms-2 text-sm text-gray-700 dark:text-gray-300">Aktif</span>
                                </label>
                                <button type="button" wire:click="testConnection('{{ $store }}')"
                                        wire:loading.attr="disabled" wire:target="testConnection('{{ $store }}')"
                                        class="px-4 py-2 bg-blue-600 text-white text-sm rounded hover:bg-blue-700 disabled:opacity-60">
                                    <span wire:loading.remove wire:target="testConnection('{{ $store }}')">Bağlantıyı Test Et</span>
                                    <span wire:loading wire:target="testConnection('{{ $store }}')">Test ediliyor…</span>
                                </button>
                            </div>
                        </div>

                        @if (! empty($testResults[$store]))
                            @php $r = $testResults[$store]; $okAll = $r['overall'] ?? false; @endphp
                            <div class="mt-4 p-3 rounded border {{ $okAll ? 'bg-green-50 dark:bg-green-900/20 border-green-300 dark:border-green-700' : 'bg-red-50 dark:bg-red-900/20 border-red-300 dark:border-red-700' }}">
                                <div class="font-semibold {{ $okAll ? 'text-green-800 dark:text-green-300' : 'text-red-800 dark:text-red-300' }}">
                                    {{ $okAll ? '✓ Bağlantı ve yetki doğrulandı' : '✗ Bağlantı/yetki sorunu' }}
                                </div>
                                <ul class="mt-2 text-xs space-y-1 text-gray-700 dark:text-gray-300">
                                    <li>📦 Ürün servisi: {{ $r['product']['message'] ?? '—' }}</li>
                                    <li>🛒 Sipariş servisi: {{ $r['order']['message'] ?? '—' }}</li>
                                </ul>
                                <p class="mt-2 text-[11px] text-gray-500 dark:text-gray-400">
                                    Kaydedilmiş bilgilerle test edilir — bilgileri değiştirdiysen önce "Kaydet".
                                </p>
                            </div>
                        @endif
                    </div>
                @endforeach

                <div class="flex justify-end">
                    <button type="submit" class="px-6 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                        Kaydet
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
