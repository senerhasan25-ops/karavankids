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

                            {{-- 7) Ödeme Durumu --}}
                            <div>
                                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Ödeme Durumu</label>
                                <select wire:model="odemeDurumu"
                                        class="w-full text-sm rounded border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200">
                                    @foreach ($odemeDurumlari as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- 8) Sipariş Durumu --}}
                            <div>
                                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Sipariş Durumu</label>
                                <select wire:model="siparisDurumu"
                                        class="w-full text-sm rounded border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200">
                                    @foreach ($siparisDurumlari as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- 9) Paketleme Durumu --}}
                            <div>
                                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Paketleme Durumu</label>
                                <select wire:model="paketlemeDurumu"
                                        class="w-full text-sm rounded border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200">
                                    @foreach ($paketlemeDurumlari as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- 10) Aktarılma Durumu --}}
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
                    {{-- Ana-durum sütunları liste boyandıktan SONRA ayrı istekte doldurulur
                         (performans: bayi listesi anında görünsün, ana SOAP'ları arkadan gelsin). --}}
                    @if (! $anaStatusesLoaded)
                        <div wire:init="enrichAnaStatuses"></div>
                    @endif

                    {{-- Toplu aktarım çubuğu — seçim varsa görünür --}}
                    @if (count($selectedBayiIds) > 0)
                        <div class="px-4 py-3 bg-indigo-50 dark:bg-indigo-900/30 border-b border-indigo-200 dark:border-indigo-800 flex items-center justify-between gap-3">
                            <div class="text-sm text-indigo-900 dark:text-indigo-200">
                                <strong>{{ count($selectedBayiIds) }}</strong> sipariş seçildi.
                            </div>
                            <div class="flex items-center gap-2">
                                <button wire:click="topluAktar"
                                    wire:confirm="Seçili {{ count($selectedBayiIds) }} sipariş aktarılacak. Devam edilsin mi?"
                                    class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs rounded-md font-semibold">
                                    🚀 Seçilenleri Aktar
                                </button>
                                <button wire:click="topluAktar(true)"
                                    wire:confirm="Seçili {{ count($selectedBayiIds) }} sipariş FORCE ile (zaten aktarılmış olsa bile) tekrar aktarılacak. Devam edilsin mi?"
                                    class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white text-xs rounded-md">
                                    ⚠️ Force Aktar
                                </button>
                                <button wire:click="clearSelection"
                                    class="px-3 py-1.5 border border-gray-300 dark:border-gray-600 text-xs rounded-md text-gray-700 dark:text-gray-300 hover:bg-white dark:hover:bg-gray-800">
                                    Seçimi Temizle
                                </button>
                            </div>
                        </div>
                    @endif

                    {{-- Toplu seçim çubuğu --}}
                    <div class="px-4 py-2 border-b border-gray-200 dark:border-gray-700 flex items-center gap-2 bg-gray-50/60 dark:bg-gray-900/30">
                        @php
                            $allIds = array_values(array_filter(array_map(fn ($x) => (string) ($x['id'] ?? ''), $orders)));
                            $allSelected = ! empty($allIds) && empty(array_diff($allIds, $selectedBayiIds));
                        @endphp
                        <button type="button" wire:click="toggleSelectAll"
                                class="text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:underline">
                            {{ $allSelected ? '☑ Tümünü kaldır' : '☐ Tümünü seç' }}
                        </button>
                        <span class="text-xs text-gray-400">·</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ count($orders) }} sipariş listelendi</span>
                    </div>

                    {{-- KART LİSTESİ --}}
                    <div class="p-3 sm:p-4 space-y-3">
                        @foreach ($orders as $o)
                            @php
                                $isSelected = in_array((string) $o['id'], $selectedBayiIds, true);
                                $st = $o['local_status'];
                                $accent = match (true) {
                                    $st === 'transferred' => 'border-l-emerald-500',
                                    $st === 'failed'      => 'border-l-red-500',
                                    $st === 'queued'      => 'border-l-amber-400',
                                    (bool) $o['entegrasyon_aktarildi'] => 'border-l-blue-400',
                                    default               => 'border-l-gray-300 dark:border-l-gray-600',
                                };
                                $isDone = in_array($st, ['transferred', 'queued'], true);
                                $expanded = $expandedOrderId === (string) $o['id'];
                            @endphp

                            <div class="rounded-lg border border-gray-200 dark:border-gray-700 border-l-4 {{ $accent }}
                                        {{ $isSelected ? 'ring-2 ring-indigo-400/60 bg-indigo-50/40 dark:bg-indigo-900/20' : 'bg-white dark:bg-gray-800' }}
                                        shadow-sm hover:shadow transition-shadow">

                                {{-- ÜST: seçim + sipariş no + tarih + durum rozeti --}}
                                <div class="flex items-start gap-3 p-3 sm:p-4">
                                    <input type="checkbox" wire:model.live="selectedBayiIds" value="{{ $o['id'] }}"
                                        title="{{ $isDone ? 'Zaten aktarılmış/kuyrukta — yalnızca Force ile tekrar gönderilir' : 'Aktarım için seç' }}"
                                        class="mt-1 rounded border-gray-300 dark:border-gray-600 focus:ring-2 {{ $isDone ? 'text-amber-500 focus:ring-amber-500' : 'text-indigo-600 focus:ring-indigo-500' }}" />

                                    <div class="flex-1 min-w-0">
                                        {{-- başlık satırı --}}
                                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                            <span class="font-semibold text-gray-900 dark:text-gray-100 font-mono">
                                                {{ $o['siparis_no'] ?: 'Sipariş #'.$o['id'] }}
                                            </span>
                                            <span class="text-[11px] font-mono text-gray-400 dark:text-gray-500">🏪 Bayi #{{ $o['id'] }}</span>
                                            @if ($o['tarih'])
                                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                                    📅 {{ \Illuminate\Support\Str::limit(str_replace('T', ' ', $o['tarih']), 16, '') }}
                                                </span>
                                            @endif

                                            {{-- durum rozeti (sağa yasla) --}}
                                            <span class="ms-auto">
                                                @if ($st === 'transferred')
                                                    <button wire:click="openDiagnostics('{{ $o['id'] }}')"
                                                            class="px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300 text-xs font-medium hover:bg-emerald-200"
                                                            title="SOAP detayı">✓ Aktarıldı @if($o['local_ana_id'])<span class="font-mono opacity-70">· Ana #{{ $o['local_ana_id'] }}</span>@endif</button>
                                                @elseif ($st === 'failed')
                                                    <button wire:click="openDiagnostics('{{ $o['id'] }}')"
                                                            class="px-2 py-0.5 rounded-full bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300 text-xs font-medium hover:bg-red-200"
                                                            title="Hata + SOAP detayı">✗ Başarısız</button>
                                                @elseif ($st === 'queued')
                                                    <span class="px-2 py-0.5 rounded-full bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300 text-xs font-medium">⏳ Kuyrukta</span>
                                                @elseif ($o['entegrasyon_aktarildi'])
                                                    <span class="px-2 py-0.5 rounded-full bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300 text-xs font-medium" title="Bayi'de işaretli ama yerel kayıt yok">Bayi'de işaretli</span>
                                                @else
                                                    <span class="px-2 py-0.5 rounded-full bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300 text-xs font-medium">● Yeni</span>
                                                @endif
                                            </span>
                                        </div>

                                        {{-- müşteri + iletişim + tutar --}}
                                        <div class="flex flex-wrap items-center gap-x-4 gap-y-0.5 mt-2">
                                            <span class="text-sm font-medium text-gray-800 dark:text-gray-200">👤 {{ $o['alici'] ?: '—' }}</span>
                                            @if ($o['mail'])<span class="text-xs text-gray-500 dark:text-gray-400">✉ {{ $o['mail'] }}</span>@endif
                                            @if ($o['telefon'])<span class="text-xs text-gray-500 dark:text-gray-400 font-mono">📞 {{ $o['telefon'] }}</span>@endif
                                            <span class="ms-auto text-base font-bold text-gray-900 dark:text-gray-100 font-mono">₺{{ number_format($o['tutar'], 2, ',', '.') }}</span>
                                        </div>

                                        {{-- durum çipleri: BAYİ --}}
                                        <div class="flex flex-wrap items-center gap-1.5 mt-2">
                                            <span class="text-[10px] uppercase font-semibold text-blue-600 dark:text-blue-400 tracking-wide">Bayi</span>
                                            <span class="px-1.5 py-0.5 rounded bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 text-[11px]">💳 {{ $odemeTipleri[(int) $o['odeme_tipi']] ?? $o['odeme_tipi'] }}</span>
                                            @if ($o['siparis_durumu'] !== '')
                                                <span class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 text-[11px]">📋 {{ $siparisDurumlari[(int) $o['siparis_durumu']] ?? $o['siparis_durumu'] }}</span>
                                            @endif
                                            @if ($o['odeme_durumu'] !== '')
                                                <span class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 text-[11px]">💰 {{ $odemeDurumlari[(int) $o['odeme_durumu']] ?? $o['odeme_durumu'] }}</span>
                                            @endif
                                            @if ($o['paketleme_durumu'] !== '')
                                                <span class="px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 text-[11px]">📦 {{ $paketlemeDurumlari[(int) $o['paketleme_durumu']] ?? $o['paketleme_durumu'] }}</span>
                                            @endif
                                        </div>

                                        {{-- durum çipleri: ANA (aktarılmışsa) --}}
                                        @if ($st === 'transferred' && $o['local_ana_id'])
                                            <div class="flex flex-wrap items-center gap-1.5 mt-1">
                                                <span class="text-[10px] uppercase font-semibold text-emerald-600 dark:text-emerald-400 tracking-wide">Ana</span>
                                                @if ($o['ana_odeme_tipi'])
                                                    <span class="px-1.5 py-0.5 rounded bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 text-[11px]">💳 {{ $odemeTipleri[(int) $o['ana_odeme_tipi']] ?? $o['ana_odeme_tipi'] }}</span>
                                                @endif
                                                @if ($o['ana_siparis_durumu'] !== null && $o['ana_siparis_durumu'] !== '')
                                                    <span class="px-1.5 py-0.5 rounded bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 text-[11px]">📋 {{ $siparisDurumlari[(int) $o['ana_siparis_durumu']] ?? $o['ana_siparis_durumu'] }}</span>
                                                @endif
                                                @if ($o['ana_paketleme_durumu'] !== null && $o['ana_paketleme_durumu'] !== '')
                                                    <span class="px-1.5 py-0.5 rounded bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 text-[11px]">📦 {{ $paketlemeDurumlari[(int) $o['ana_paketleme_durumu']] ?? $o['ana_paketleme_durumu'] }}</span>
                                                @endif
                                                @if (! $o['ana_siparis_durumu'] && ! $o['ana_odeme_durumu'] && ! $o['ana_paketleme_durumu'])
                                                    @if (! $anaStatusesLoaded)
                                                        <span class="text-[11px] text-gray-400 italic flex items-center gap-1">
                                                            <span class="inline-block animate-spin rounded-full h-2.5 w-2.5 border border-emerald-400 border-t-transparent"></span> yükleniyor…
                                                        </span>
                                                    @else
                                                        <span class="text-[11px] text-gray-400 italic">— veri çekilemedi —</span>
                                                    @endif
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                </div>

                                {{-- ALT: aksiyon çubuğu --}}
                                <div class="flex flex-wrap items-center gap-2 px-3 sm:px-4 py-2 border-t border-gray-100 dark:border-gray-700/60 bg-gray-50/50 dark:bg-gray-900/20 rounded-b-lg">
                                    <button wire:click="toggleOrderProducts('{{ $o['id'] }}')"
                                            class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium bg-slate-600 text-white rounded hover:bg-slate-700">
                                        📦 {{ count($o['urunler']) }} ürün <span>{{ $expanded ? '▲' : '▼' }}</span>
                                    </button>
                                    <button wire:click="openEditor('{{ $o['id'] }}')"
                                            class="inline-flex items-center gap-1 px-2.5 py-1 text-xs text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-700 rounded hover:bg-indigo-50 dark:hover:bg-indigo-900/30">
                                        ✏️ Düzenle
                                    </button>
                                    @if (! empty($o['has_override']))
                                        <span class="px-1.5 py-0.5 text-[10px] bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-300 rounded" title="Ürün düzenlemesi yapılmış">✏️ Düzenlendi</span>
                                    @endif
                                    <button wire:click="openStatusEditor('{{ $o['id'] }}')"
                                            class="inline-flex items-center gap-1 px-2.5 py-1 text-xs text-teal-700 dark:text-teal-300 border border-teal-200 dark:border-teal-700 rounded hover:bg-teal-50 dark:hover:bg-teal-900/30">
                                        ✎ Durumlar
                                    </button>

                                    {{-- Aktar (sağa yasla) --}}
                                    <span class="ms-auto">
                                        @if ($st === 'transferred')
                                            <button wire:click="aktar('{{ $o['id'] }}', true)"
                                                    wire:confirm="Bu sipariş zaten aktarılmış. Tekrar (zorla) aktarmak ana mağazada DUPLICATE oluşturabilir. Devam edilsin mi?"
                                                    class="px-3 py-1.5 text-xs font-medium bg-amber-500 text-white rounded hover:bg-amber-600">↻ Zorla Aktar</button>
                                        @elseif ($st === 'queued')
                                            <button disabled class="px-3 py-1.5 text-xs bg-gray-200 dark:bg-gray-700 text-gray-400 rounded cursor-not-allowed">Kuyrukta…</button>
                                        @else
                                            <button wire:click="aktar('{{ $o['id'] }}')"
                                                    class="px-3 py-1.5 text-xs font-semibold bg-green-600 text-white rounded hover:bg-green-700 shadow-sm">🚀 Aktar</button>
                                        @endif
                                    </span>
                                </div>

                                {{-- GENİŞLETİLMİŞ ÜRÜN TABLOSU --}}
                                @if ($expanded)
                                    <div class="border-t border-gray-200 dark:border-gray-700 p-3 sm:p-4 bg-slate-50/70 dark:bg-gray-900/40 rounded-b-lg">
                                        @if (empty($o['urunler']))
                                            <div class="text-xs text-gray-500 dark:text-gray-400 italic">Bu siparişte ürün bilgisi bulunamadı.</div>
                                        @else
                                            <div class="overflow-x-auto border border-gray-200 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800">
                                                <table class="w-full text-xs">
                                                    <thead class="bg-gray-100 dark:bg-gray-900 text-[10px] uppercase text-gray-500 dark:text-gray-400">
                                                        <tr>
                                                            <th class="px-3 py-2 text-left">Stok Kodu</th>
                                                            <th class="px-3 py-2 text-left">Barkod</th>
                                                            <th class="px-3 py-2 text-left">Ürün Adı</th>
                                                            <th class="px-3 py-2 text-center">Adet</th>
                                                            <th class="px-3 py-2 text-right">Birim Fiyat</th>
                                                            <th class="px-3 py-2 text-right">Toplam</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                                        @foreach ($o['urunler'] as $line)
                                                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                                                <td class="px-3 py-2 font-mono">{{ $line['stok_kodu'] ?: '—' }}</td>
                                                                <td class="px-3 py-2 font-mono text-gray-500">{{ $line['barkod'] ?: '—' }}</td>
                                                                <td class="px-3 py-2">{{ $line['urun_adi'] }}</td>
                                                                <td class="px-3 py-2 text-center font-semibold">{{ $line['adet'] }}</td>
                                                                <td class="px-3 py-2 text-right font-mono">₺{{ number_format($line['birim_fiyat'], 2, ',', '.') }}</td>
                                                                <td class="px-3 py-2 text-right font-mono">₺{{ number_format($line['toplam'], 2, ',', '.') }}</td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
                </div>{{-- /flex-1 sağ taraf --}}
            </div>{{-- /flex container --}}
        </div>
    </div>

    {{-- ÜRÜN DÜZENLEME MODAL'I --}}
    @if ($showEditor)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60"
             wire:click.self="closeEditor"
             x-data x-on:keydown.escape.window="$wire.closeEditor()">

            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] flex flex-col">

                {{-- Modal header --}}
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200">
                        📦 Sipariş #{{ $editingBayiOrderId }} — Ürünler
                        @if ($hasOverride)
                            <span class="ms-2 text-xs px-2 py-0.5 bg-purple-100 text-purple-800 rounded align-middle">
                                ✏️ Önceden düzenlenmiş
                            </span>
                        @endif
                    </h3>
                    <button wire:click="closeEditor"
                            class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 text-2xl leading-none">
                        ×
                    </button>
                </div>

                {{-- Modal body --}}
                <div class="px-6 py-4 overflow-y-auto flex-1">
                    @if ($editorLoading)
                        <div class="p-8 text-center text-gray-500">
                            <div class="inline-block animate-spin rounded-full h-8 w-8 border-2 border-blue-500 border-t-transparent"></div>
                            <p class="mt-2 text-sm">Sipariş ürünleri yükleniyor...</p>
                        </div>
                    @elseif ($editorError)
                        <div class="bg-red-100 border border-red-300 text-red-800 px-4 py-2 rounded text-sm">
                            <strong>Hata:</strong> {{ $editorError }}
                        </div>
                    @elseif (empty($editingLines))
                        <div class="p-8 text-center text-gray-500 text-sm">Bu siparişte ürün bulunamadı.</div>
                    @else
                        <div class="text-xs text-gray-500 dark:text-gray-400 mb-3">
                            ℹ️ Burada yaptığın değişiklikler sadece ana mağazaya aktarımı etkiler.
                            Bayi'deki orijinal sipariş dokunulmadan kalır.
                        </div>

                        <div class="overflow-x-auto border border-gray-200 dark:border-gray-700 rounded">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50 dark:bg-gray-900 text-xs uppercase text-gray-500">
                                    <tr>
                                        <th class="px-3 py-2 text-left">Stok Kodu</th>
                                        <th class="px-3 py-2 text-left">Ürün Adı</th>
                                        <th class="px-3 py-2 text-center w-24">Adet</th>
                                        <th class="px-3 py-2 text-right">Birim Fiyat</th>
                                        <th class="px-3 py-2 text-right">Satır Toplam</th>
                                        <th class="px-3 py-2 text-center w-20">Sil</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    @foreach ($editingLines as $idx => $line)
                                        <tr class="{{ ! empty($line['removed']) ? 'opacity-50 bg-red-50 dark:bg-red-900/20' : '' }}">
                                            <td class="px-3 py-2 font-mono text-xs">{{ $line['stok_kodu'] }}</td>
                                            <td class="px-3 py-2">
                                                <span class="{{ ! empty($line['removed']) ? 'line-through' : '' }}">
                                                    {{ $line['urun_adi'] }}
                                                </span>
                                            </td>
                                            <td class="px-3 py-2 text-center">
                                                <input type="number" min="1"
                                                       wire:model="editingLines.{{ $idx }}.adet"
                                                       @disabled(! empty($line['removed']))
                                                       class="w-20 text-center text-sm rounded border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200">
                                            </td>
                                            <td class="px-3 py-2 text-right font-mono">
                                                ₺{{ number_format($line['birim_fiyat'], 2, ',', '.') }}
                                            </td>
                                            <td class="px-3 py-2 text-right font-mono font-semibold">
                                                ₺{{ number_format($line['birim_fiyat'] * (int) $line['adet'], 2, ',', '.') }}
                                            </td>
                                            <td class="px-3 py-2 text-center">
                                                @if (! empty($line['removed']))
                                                    <button wire:click="toggleRemoveLine({{ $idx }})"
                                                            class="px-2 py-1 text-xs bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded hover:bg-gray-300">
                                                        ↺ Geri al
                                                    </button>
                                                @else
                                                    <button wire:click="toggleRemoveLine({{ $idx }})"
                                                            class="px-2 py-1 text-xs bg-red-500 text-white rounded hover:bg-red-600">
                                                        🗑 Sil
                                                    </button>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        {{-- Toplam (silinen satırlar hariç) --}}
                        @php
                            $modalToplam = 0;
                            foreach ($editingLines as $l) {
                                if (! empty($l['removed'])) continue;
                                $modalToplam += $l['birim_fiyat'] * (int) $l['adet'];
                            }
                        @endphp
                        <div class="mt-3 text-right text-sm text-gray-700 dark:text-gray-300">
                            Ürün Ara Toplam:
                            <span class="font-mono font-semibold">₺{{ number_format($modalToplam, 2, ',', '.') }}</span>
                            <span class="text-xs text-gray-500">(kargo hariç)</span>
                        </div>
                    @endif
                </div>

                {{-- Modal footer --}}
                <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between">
                    @if ($hasOverride)
                        <button wire:click="clearOverride"
                                wire:confirm="Bu siparişin tüm ürün düzenlemeleri silinecek ve orijinal bayi siparişi aktarılacak. Devam edilsin mi?"
                                class="px-3 py-2 text-sm text-red-600 hover:text-red-800 hover:underline">
                            ↺ Düzenlemeleri sıfırla
                        </button>
                    @else
                        <span></span>
                    @endif

                    <div class="flex gap-2">
                        <button wire:click="closeEditor"
                                class="px-4 py-2 text-sm bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded hover:bg-gray-300">
                            İptal
                        </button>
                        <button wire:click="saveEdits"
                                @disabled($editorLoading || ! empty($editorError) || empty($editingLines))
                                class="px-4 py-2 text-sm bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50">
                            💾 Kaydet
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- DURUM GÜNCELLEME MODAL'I --}}
    @if ($showStatusEditor)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60"
             wire:click.self="closeStatusEditor"
             x-data x-on:keydown.escape.window="$wire.closeStatusEditor()">

            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-lg w-full max-h-[90vh] flex flex-col">

                {{-- Modal header --}}
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200">
                        ✎ Sipariş #{{ $statusEditingBayiOrderId }} — Durumlar
                    </h3>
                    <button wire:click="closeStatusEditor"
                            class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 text-2xl leading-none">
                        ×
                    </button>
                </div>

                {{-- Modal body --}}
                <div class="px-6 py-4 overflow-y-auto flex-1 space-y-4">

                    {{-- Hangi taraf? Tab benzeri toggle --}}
                    <div class="flex border-b border-gray-200 dark:border-gray-700">
                        <button wire:click="setStatusTarget('bayi')"
                                class="px-4 py-2 text-sm font-medium border-b-2 -mb-px
                                       {{ $statusEditTarget === 'bayi' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                            🏪 Bayi'de
                        </button>
                        <button wire:click="setStatusTarget('ana')"
                                @disabled(! $statusEditAnaOrderId)
                                title="{{ ! $statusEditAnaOrderId ? 'Sipariş henüz ana\'ya aktarılmamış' : '' }}"
                                class="px-4 py-2 text-sm font-medium border-b-2 -mb-px
                                       {{ $statusEditTarget === 'ana' ? 'border-blue-600 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700' }}
                                       disabled:opacity-40 disabled:cursor-not-allowed">
                            🏢 Ana'da
                            @if ($statusEditAnaOrderId)
                                <span class="text-xs text-gray-400">(#{{ $statusEditAnaOrderId }})</span>
                            @endif
                        </button>
                    </div>

                    @if ($statusEditorSuccess)
                        <div class="bg-green-100 border border-green-300 text-green-800 px-3 py-2 rounded text-sm">
                            {{ $statusEditorSuccess }}
                        </div>
                    @endif
                    @if ($statusEditorError)
                        <div class="bg-red-100 border border-red-300 text-red-800 px-3 py-2 rounded text-sm">
                            <strong>Hata:</strong> {{ $statusEditorError }}
                        </div>
                    @endif

                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        ℹ️ Sadece <strong>değiştirmek istediğin</strong> alanları seç.
                        "Değiştirme" seçili kalan alanlar dokunulmadan bırakılır.
                    </div>

                    @php
                        $activeSiparisCodes = $statusEditTarget === 'bayi' ? $bayiSupportedSiparisCodes : $anaSupportedSiparisCodes;
                        $activeOdemeCodes = $statusEditTarget === 'bayi' ? $bayiSupportedOdemeCodes : $anaSupportedOdemeCodes;
                    @endphp

                    {{-- Sipariş Durumu --}}
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Sipariş Durumu</label>
                        <select wire:model="editSiparisDurumu"
                                class="w-full text-sm rounded border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200">
                            <option value="-1">— Değiştirme —</option>
                            @foreach ($siparisDurumlari as $key => $label)
                                @if ($key !== -1)
                                    @if (in_array($key, $activeSiparisCodes, true))
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @else
                                        <option value="{{ $key }}" disabled>{{ $label }} (bu mağazada yok)</option>
                                    @endif
                                @endif
                            @endforeach
                        </select>
                    </div>

                    {{-- Ödeme Durumu --}}
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Ödeme Durumu</label>
                        <select wire:model="editOdemeDurumu"
                                class="w-full text-sm rounded border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200">
                            <option value="-1">— Değiştirme —</option>
                            @foreach ($odemeDurumlari as $key => $label)
                                @if ($key !== -1)
                                    @if (in_array($key, $activeOdemeCodes, true))
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @else
                                        <option value="{{ $key }}" disabled>{{ $label }} (SOAP'ta yok)</option>
                                    @endif
                                @endif
                            @endforeach
                        </select>
                    </div>

                    {{-- Paketleme Durumu --}}
                    <div>
                        <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">Paketleme Durumu</label>
                        <select wire:model="editPaketlemeDurumu"
                                class="w-full text-sm rounded border-gray-300 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-200">
                            <option value="-1">— Değiştirme —</option>
                            @foreach ($paketlemeDurumlari as $key => $label)
                                @if ($key !== -1)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                </div>

                {{-- Modal footer --}}
                <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex items-center justify-end gap-2">
                    <button wire:click="closeStatusEditor"
                            class="px-4 py-2 text-sm bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded hover:bg-gray-300">
                        Kapat
                    </button>
                    <button wire:click="saveStatusUpdates" wire:loading.attr="disabled" wire:target="saveStatusUpdates"
                            class="px-4 py-2 text-sm bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50">
                        <span wire:loading.remove wire:target="saveStatusUpdates">
                            💾 {{ $statusEditTarget === 'bayi' ? 'Bayi\'de' : 'Ana\'da' }} Kaydet
                        </span>
                        <span wire:loading wire:target="saveStatusUpdates">⏳ Güncelleniyor...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- AKTARIM DETAY MODAL'I (SOAP request/response + hata) --}}
    @if ($showDiagnostics)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60"
             wire:click.self="closeDiagnostics"
             x-data x-on:keydown.escape.window="$wire.closeDiagnostics()">

            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-5xl w-full max-h-[90vh] flex flex-col">

                {{-- Modal header --}}
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200">
                        🔍 Sipariş #{{ $diagBayiOrderId }} — Aktarım Detayı
                    </h3>
                    <button wire:click="closeDiagnostics"
                            class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 text-2xl leading-none">
                        ×
                    </button>
                </div>

                {{-- Modal body --}}
                <div class="px-6 py-4 overflow-y-auto flex-1 space-y-4">
                    @if (empty($diagLogs))
                        <div class="p-8 text-center text-gray-500 text-sm">
                            Bu sipariş için aktarım kaydı henüz yok.
                            <br><span class="text-xs">İlk aktarım denenince burada SOAP request/response detayı görünecek.</span>
                        </div>
                    @else
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            En son {{ count($diagLogs) }} aktarım denemesi gösteriliyor (yeniden eskiye).
                        </div>

                        @foreach ($diagLogs as $log)
                            <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                                {{-- Log header --}}
                                <div class="px-4 py-2 flex items-center justify-between
                                            {{ $log['status'] === 'success' ? 'bg-green-50 dark:bg-green-900/20' : 'bg-red-50 dark:bg-red-900/20' }}">
                                    <div class="flex items-center gap-3">
                                        <span class="px-2 py-0.5 rounded text-xs font-semibold
                                                     {{ $log['status'] === 'success' ? 'bg-green-200 text-green-800' : 'bg-red-200 text-red-800' }}">
                                            {{ $log['status'] === 'success' ? '✓ Başarılı' : '✗ Hata' }}
                                        </span>
                                        <span class="text-xs text-gray-600 dark:text-gray-400 font-mono">
                                            {{ $log['action'] }}
                                        </span>
                                        <span class="text-xs text-gray-500">{{ $log['created_at'] }}</span>
                                    </div>
                                    <span class="text-xs text-gray-400">Log #{{ $log['id'] }}</span>
                                </div>

                                {{-- Mesaj --}}
                                @if (! empty($log['message']))
                                    <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
                                        <div class="text-xs uppercase text-gray-500 mb-1">Mesaj / Hata</div>
                                        <pre class="text-xs text-gray-800 dark:text-gray-200 whitespace-pre-wrap break-words font-mono bg-gray-50 dark:bg-gray-900 p-2 rounded max-h-48 overflow-y-auto">{{ $log['message'] }}</pre>
                                    </div>
                                @endif

                                {{-- SOAP Request --}}
                                @if (! empty($log['raw_request']))
                                    <details class="border-t border-gray-200 dark:border-gray-700">
                                        <summary class="px-4 py-2 cursor-pointer text-xs text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-900/50">
                                            📤 SOAP Request (Bayi/Ana payload + XML) — tıkla aç/kapat
                                        </summary>
                                        <pre class="text-[10px] font-mono bg-gray-50 dark:bg-gray-900 text-gray-700 dark:text-gray-300 p-3 max-h-96 overflow-auto whitespace-pre-wrap break-words">{{ $log['raw_request'] }}</pre>
                                    </details>
                                @endif

                                {{-- SOAP Response --}}
                                @if (! empty($log['raw_response']))
                                    <details class="border-t border-gray-200 dark:border-gray-700">
                                        <summary class="px-4 py-2 cursor-pointer text-xs text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-900/50">
                                            📥 SOAP Response (Ticimax cevabı) — tıkla aç/kapat
                                        </summary>
                                        <pre class="text-[10px] font-mono bg-gray-50 dark:bg-gray-900 text-gray-700 dark:text-gray-300 p-3 max-h-96 overflow-auto whitespace-pre-wrap break-words">{{ $log['raw_response'] }}</pre>
                                    </details>
                                @endif
                            </div>
                        @endforeach
                    @endif
                </div>

                {{-- Modal footer --}}
                <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between">
                    <a href="{{ route('loglar') }}?search={{ $diagBayiOrderId }}"
                       class="text-sm text-blue-600 hover:underline">
                        Tüm logları görüntüle →
                    </a>
                    <button wire:click="closeDiagnostics"
                            class="px-4 py-2 text-sm bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded hover:bg-gray-300">
                        Kapat
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
