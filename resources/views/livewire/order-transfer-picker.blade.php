<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Sipariş Aktarım Paneli
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            @if (session('status'))
                <div class="bg-green-100 border border-green-300 text-green-800 px-4 py-2 rounded mb-4">
                    {{ session('status') }}
                </div>
            @endif

            {{-- 2-kolon: solda dar filtre paneli, sağda geniş sonuç tablosu --}}
            <div class="flex flex-col lg:flex-row gap-4">

                {{-- FİLTRE PANELİ — sol, sabit dar genişlik --}}
                <aside class="lg:w-72 lg:flex-shrink-0">
                    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4 lg:sticky lg:top-4">
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">🔍 Filtrele</h3>

                        <div class="space-y-3">
                            {{-- 1) Alıcı Adı --}}
                            <div>
                                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Alıcı Adı / Soyadı</label>
                                <input type="text" wire:model="aliciAdi" placeholder="alıcı adı veya soyadı"
                                       class="w-full text-sm rounded border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200">
                            </div>

                            {{-- 2) E-mail --}}
                            <div>
                                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">E-mail</label>
                                <input type="text" wire:model="aliciMail" placeholder="ornek@ornekmail.com"
                                       class="w-full text-sm rounded border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200">
                            </div>

                            {{-- 3) Telefon --}}
                            <div>
                                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Telefon</label>
                                <input type="text" wire:model="telefon" placeholder="5551112233"
                                       class="w-full text-sm rounded border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200">
                            </div>

                            {{-- 4) Sipariş No --}}
                            <div>
                                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Sipariş No</label>
                                <input type="text" wire:model="siparisNo" placeholder="541WC8426P"
                                       class="w-full text-sm rounded border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200">
                            </div>

                            {{-- 5) Tarih aralığı (yan yana, kompakt) --}}
                            <div>
                                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Tarih Aralığı</label>
                                <div class="grid grid-cols-2 gap-2">
                                    <input type="date" wire:model="dateFrom"
                                           class="w-full text-xs rounded border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200">
                                    <input type="date" wire:model="dateTo"
                                           class="w-full text-xs rounded border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200">
                                </div>
                            </div>

                            {{-- 6) Ödeme Tipi --}}
                            <div>
                                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Ödeme Tipi</label>
                                <select wire:model="odemeTipi"
                                        class="w-full text-sm rounded border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200">
                                    @foreach ($odemeTipleri as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- 7) Aktarılma Durumu --}}
                            <div>
                                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Aktarılma Durumu</label>
                                <select wire:model="aktarildi"
                                        class="w-full text-sm rounded border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200">
                                    <option value="-1">Hepsi</option>
                                    <option value="0">Henüz aktarılmamış</option>
                                    <option value="1">Aktarılmış</option>
                                </select>
                            </div>

                            {{-- 8) Listele + Reset --}}
                            <div class="flex gap-2 pt-2">
                                <button wire:click="listele" wire:loading.attr="disabled"
                                        class="flex-1 px-3 py-2 bg-blue-600 text-white text-sm font-medium rounded hover:bg-blue-700 disabled:opacity-50">
                                    <span wire:loading.remove wire:target="listele">📋 Listele</span>
                                    <span wire:loading wire:target="listele">⏳ ...</span>
                                </button>
                                <button wire:click="resetFilters" type="button" title="Filtreleri temizle"
                                        class="px-3 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 text-sm rounded hover:bg-gray-300">
                                    ↺
                                </button>
                            </div>
                        </div>
                    </div>
                </aside>

                {{-- SAĞ TARAF: hata + sonuç tablosu --}}
                <div class="flex-1 min-w-0">

            @if ($lastError)
                <div class="bg-red-100 border border-red-300 text-red-800 px-4 py-2 rounded mb-4 text-sm">
                    <strong>Hata:</strong> {{ $lastError }}
                </div>
            @endif

            {{-- SONUÇ LİSTESİ --}}
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                        Bayi Siparişleri
                        @if ($hasSearched && ! $loading)
                            <span class="text-xs text-gray-500 ms-2">({{ count($orders) }} sonuç · sayfa {{ $page }})</span>
                        @endif
                    </h3>
                    @if ($hasSearched)
                        <div class="flex gap-2">
                            <button wire:click="sayfaOnceki" @disabled($page <= 1 || $loading)
                                    class="px-2 py-1 text-xs bg-gray-200 dark:bg-gray-700 rounded disabled:opacity-50">‹ Önceki</button>
                            <button wire:click="sayfaSonraki" @disabled($loading || count($orders) < $perPage)
                                    class="px-2 py-1 text-xs bg-gray-200 dark:bg-gray-700 rounded disabled:opacity-50">Sonraki ›</button>
                        </div>
                    @endif
                </div>

                @if ($loading)
                    <div class="p-8 text-center text-gray-500">
                        <div class="inline-block animate-spin rounded-full h-6 w-6 border-2 border-blue-500 border-t-transparent"></div>
                        <p class="mt-2 text-sm">Bayi'den siparişler çekiliyor...</p>
                    </div>
                @elseif (! $hasSearched)
                    <div class="p-8 text-center text-gray-500 text-sm">
                        Yukarıdaki filtreleri ayarlayıp <strong>Listele</strong> butonuna bas.
                    </div>
                @elseif (empty($orders))
                    <div class="p-8 text-center text-gray-500 text-sm">
                        Bu filtreyle eşleşen sipariş bulunamadı.
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-900 text-xs uppercase text-gray-500 dark:text-gray-400">
                                <tr>
                                    <th class="px-3 py-2 text-left">Bayi ID</th>
                                    <th class="px-3 py-2 text-left">Sipariş No</th>
                                    <th class="px-3 py-2 text-left">Tarih</th>
                                    <th class="px-3 py-2 text-left">Alıcı</th>
                                    <th class="px-3 py-2 text-left">İletişim</th>
                                    <th class="px-3 py-2 text-right">Tutar</th>
                                    <th class="px-3 py-2 text-left">Ödeme</th>
                                    <th class="px-3 py-2 text-center">Durum</th>
                                    <th class="px-3 py-2 text-right">İşlem</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                @foreach ($orders as $o)
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-900/50">
                                        <td class="px-3 py-2 font-mono text-xs text-gray-600 dark:text-gray-400">{{ $o['id'] }}</td>
                                        <td class="px-3 py-2 font-mono">{{ $o['siparis_no'] ?: '—' }}</td>
                                        <td class="px-3 py-2 text-xs text-gray-600 dark:text-gray-400">
                                            {{ $o['tarih'] ? \Illuminate\Support\Str::limit(str_replace('T', ' ', $o['tarih']), 16, '') : '—' }}
                                        </td>
                                        <td class="px-3 py-2">{{ $o['alici'] ?: '—' }}</td>
                                        <td class="px-3 py-2 text-xs">
                                            @if ($o['mail'])
                                                <div class="text-gray-700 dark:text-gray-300">{{ $o['mail'] }}</div>
                                            @endif
                                            @if ($o['telefon'])
                                                <div class="text-gray-500 dark:text-gray-400 font-mono">{{ $o['telefon'] }}</div>
                                            @endif
                                            @if (! $o['mail'] && ! $o['telefon'])—@endif
                                        </td>
                                        <td class="px-3 py-2 text-right font-mono">₺{{ number_format($o['tutar'], 2, ',', '.') }}</td>
                                        <td class="px-3 py-2 text-xs">
                                            {{ $odemeTipleri[(int) $o['odeme_tipi']] ?? $o['odeme_tipi'] }}
                                        </td>
                                        <td class="px-3 py-2 text-center">
                                            @if ($o['local_status'] === 'transferred')
                                                <span class="inline-block px-2 py-0.5 rounded bg-green-100 text-green-800 text-xs"
                                                      title="Ana #{{ $o['local_ana_id'] }}">
                                                    ✓ Aktarıldı
                                                </span>
                                            @elseif ($o['local_status'] === 'failed')
                                                <span class="inline-block px-2 py-0.5 rounded bg-red-100 text-red-800 text-xs"
                                                      title="{{ \Illuminate\Support\Str::limit($o['local_error'] ?? '', 200) }}">
                                                    ✗ Başarısız
                                                </span>
                                            @elseif ($o['local_status'] === 'queued')
                                                <span class="inline-block px-2 py-0.5 rounded bg-amber-100 text-amber-800 text-xs">
                                                    ⏳ Kuyrukta
                                                </span>
                                            @elseif ($o['entegrasyon_aktarildi'])
                                                <span class="inline-block px-2 py-0.5 rounded bg-blue-100 text-blue-800 text-xs"
                                                      title="Bayi'de işaretli ama yerel kayıt yok">
                                                    Bayi'de işaretli
                                                </span>
                                            @else
                                                <span class="inline-block px-2 py-0.5 rounded bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200 text-xs">
                                                    Yeni
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 text-right">
                                            @if ($o['local_status'] === 'transferred')
                                                <button wire:click="aktar('{{ $o['id'] }}', true)"
                                                        wire:confirm="Bu sipariş zaten aktarılmış. Tekrar (zorla) aktarmak ana mağazada DUPLICATE oluşturabilir. Devam edilsin mi?"
                                                        class="px-2 py-1 text-xs bg-amber-500 text-white rounded hover:bg-amber-600">
                                                    ↻ Zorla
                                                </button>
                                            @elseif ($o['local_status'] === 'queued')
                                                <button disabled class="px-2 py-1 text-xs bg-gray-300 text-gray-500 rounded cursor-not-allowed">
                                                    Kuyrukta
                                                </button>
                                            @else
                                                <button wire:click="aktar('{{ $o['id'] }}')"
                                                        class="px-2 py-1 text-xs bg-green-600 text-white rounded hover:bg-green-700">
                                                    🚀 Aktar
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
                </div>{{-- /flex-1 sağ taraf --}}
            </div>{{-- /flex container --}}
        </div>
    </div>
</div>
