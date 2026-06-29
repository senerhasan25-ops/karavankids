<div wire:poll.15s>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Dashboard</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            {{-- Top summary cards --}}
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
                    <div class="text-xs uppercase text-gray-500">Bugün Aktarılan Sipariş</div>
                    <div class="text-3xl font-semibold text-gray-900 dark:text-gray-100 mt-1">{{ $ordersToday }}</div>
                </div>
                <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
                    <div class="text-xs uppercase text-gray-500">Bugün Senkronize Edilen Ürün</div>
                    <div class="text-3xl font-semibold text-gray-900 dark:text-gray-100 mt-1">{{ $productsToday }}</div>
                </div>
                <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
                    <div class="text-xs uppercase text-gray-500">Otomatik Sync</div>
                    <div class="mt-1">
                        @if ($autoEnabled)
                            <span class="inline-block px-2 py-1 text-sm bg-green-100 text-green-800 rounded">Açık</span>
                            <div class="text-xs text-gray-500 mt-1">Her {{ $intervalMinutes }} dakikada</div>
                        @else
                            <span class="inline-block px-2 py-1 text-sm bg-gray-200 text-gray-700 rounded">Kapalı</span>
                            <a href="{{ route('ayarlar.sync') }}" class="block text-xs text-blue-600 hover:underline mt-1">Aç →</a>
                        @endif
                    </div>
                </div>
                <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
                    <div class="text-xs uppercase text-gray-500">Scheduler</div>
                    <div class="text-xs text-gray-700 dark:text-gray-300 mt-1 space-y-0.5">
                        <div>Son: {{ $lastRun?->format('Y-m-d H:i') ?? '—' }}</div>
                        <div>Sıradaki: {{ $nextRun?->format('Y-m-d H:i') ?? '—' }}</div>
                    </div>
                </div>
            </div>

            {{-- EŞLEŞTİRME SAĞLIK RAPORU --}}
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">🗺️ Eşleştirme Sağlık Raporu</h3>
                    <div class="flex items-center gap-3">
                        <span class="text-xs text-gray-500 dark:text-gray-400">Toplam {{ number_format($mapTotal) }} varyasyon haritası · 15sn'de bir otomatik yenilenir</span>
                        <button wire:click="$refresh" wire:loading.attr="disabled"
                                class="text-xs px-2 py-1 rounded border border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700">
                            <span wire:loading.remove wire:target="$refresh">↻ Yenile</span>
                            <span wire:loading wire:target="$refresh">…</span>
                        </button>
                    </div>
                </div>

                {{-- Kapsama barı --}}
                <div class="mb-4">
                    <div class="flex items-center justify-between text-xs mb-1">
                        <span class="text-gray-600 dark:text-gray-400">Eşleşme kapsamı (bayide karşılığı olan)</span>
                        <span class="font-semibold {{ $coverage >= 90 ? 'text-green-600 dark:text-green-400' : ($coverage >= 60 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400') }}">%{{ $coverage }}</span>
                    </div>
                    <div class="w-full h-3 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                        <div class="h-full transition-all {{ $coverage >= 90 ? 'bg-green-500' : ($coverage >= 60 ? 'bg-amber-500' : 'bg-red-500') }}"
                             style="width: {{ $coverage }}%"></div>
                    </div>
                </div>

                {{-- Alt metrikler --}}
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div class="rounded-lg bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 p-3">
                        <div class="text-2xl font-bold text-green-700 dark:text-green-400">{{ number_format($mapSynced) }}</div>
                        <div class="text-xs text-gray-600 dark:text-gray-400 mt-0.5">✓ Eşleşti (bayide var)</div>
                    </div>
                    <div class="rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 p-3">
                        <div class="text-2xl font-bold text-amber-700 dark:text-amber-400">{{ number_format($mapNoBayi) }}</div>
                        <div class="text-xs text-gray-600 dark:text-gray-400 mt-0.5">⏳ Bayide yok (aktarılacak)</div>
                    </div>
                    <div class="rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 p-3">
                        <div class="text-2xl font-bold text-red-700 dark:text-red-400">{{ number_format($mapError) }}</div>
                        <div class="text-xs text-gray-600 dark:text-gray-400 mt-0.5">✗ Hatalı kayıt</div>
                    </div>
                    <div class="rounded-lg bg-gray-50 dark:bg-gray-900/40 border border-gray-200 dark:border-gray-700 p-3">
                        <div class="text-2xl font-bold text-gray-700 dark:text-gray-300">{{ number_format($mapNoTed) }}</div>
                        <div class="text-xs text-gray-600 dark:text-gray-400 mt-0.5">⚠ Tedarikçi kodu yok</div>
                    </div>
                </div>
                <p class="mt-2 text-[11px] text-gray-400 dark:text-gray-500 leading-relaxed">
                    İlk üç kutu birbirini tamamlar (Eşleşti + Bayide yok + Hatalı ≈ toplam). “Tedarikçi kodu yok” ise
                    <em>ayrı bir ölçüdür</em> — eşleşmiş kayıtlarla örtüşebilir; bu kayıtlar eski stok/barkod tabanlı
                    eşleştirmeden kalmadır ve “Haritayı Yeniden Kur” ile zamanla temizlenir.
                </p>

                {{-- Sync zamanları + 24s hata --}}
                <div class="mt-4 pt-3 border-t border-gray-200 dark:border-gray-700 grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs text-gray-600 dark:text-gray-400">
                    <div>📦 Son ürün sync:
                        <strong class="text-gray-800 dark:text-gray-200">{{ $lastNewProducts ? \Illuminate\Support\Carbon::parse($lastNewProducts)->format('d.m.Y H:i') : 'Henüz yok' }}</strong>
                    </div>
                    <div>💰 Son stok/fiyat sync:
                        <strong class="text-gray-800 dark:text-gray-200">{{ $lastStockPrice ? \Illuminate\Support\Carbon::parse($lastStockPrice)->format('d.m.Y H:i') : 'Henüz yok' }}</strong>
                    </div>
                    <div>🔴 Son 24 saat hata:
                        <strong class="{{ $errors24h > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-800 dark:text-gray-200' }}">{{ $errors24h }}</strong>
                        @if ($errors24h > 0)
                            <a href="{{ route('loglar') }}" class="text-blue-600 hover:underline ms-1">(incele →)</a>
                        @endif
                    </div>
                </div>

                @if ($mapTotal === 0)
                    <div class="mt-3 text-xs text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 rounded p-2">
                        Henüz harita kurulmamış. <a href="{{ route('ayarlar.sync') }}" class="underline">Otomatik Güncelleme</a> sayfasından
                        <strong>"Haritayı Yeniden Kur"</strong> ile başla — ürün oluşturmadan eşleştirme yapar.
                    </div>
                @endif
            </div>

            {{-- BAYİDE OLMAYAN ÜRÜNLER --}}
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                        ⏳ Bayide Olmayan Ürünler
                        <span class="text-xs font-normal text-gray-500 dark:text-gray-400">({{ number_format($mapNoBayi) }} kayıt)</span>
                    </h3>
                    @if ($mapNoBayi > 0)
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            Bunlar ana'da var, bayide yok — "Tüm Ürünleri Aktar" ile oluşturulur.
                        </span>
                    @endif
                </div>

                @if ($notInBayi->isEmpty())
                    <div class="text-sm text-gray-500 dark:text-gray-400 italic">
                        Bayide olmayan ürün yok — tüm eşleşmeler bayide mevcut 🎉
                    </div>
                @else
                    <div class="overflow-x-auto max-h-96 overflow-y-auto border border-gray-200 dark:border-gray-700 rounded-lg">
                        <table class="w-full text-xs">
                            <thead class="bg-gray-50 dark:bg-gray-900 text-[10px] uppercase text-gray-500 dark:text-gray-400 sticky top-0">
                                <tr>
                                    <th class="px-3 py-2 text-left">Stok Kodu</th>
                                    <th class="px-3 py-2 text-left">Barkod</th>
                                    <th class="px-3 py-2 text-left">Tedarikçi Kodu</th>
                                    <th class="px-3 py-2 text-left">Ana Ürün ID</th>
                                    <th class="px-3 py-2 text-left">Durum</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                @foreach ($notInBayi as $m)
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                        <td class="px-3 py-1.5 font-mono">{{ $m->stok_kodu ?: '—' }}</td>
                                        <td class="px-3 py-1.5 font-mono text-gray-500">{{ $m->barcode ?: '—' }}</td>
                                        <td class="px-3 py-1.5 font-mono text-gray-500">{{ $m->tedarikci_kodu ?: '—' }}</td>
                                        <td class="px-3 py-1.5 font-mono">{{ $m->ana_product_id ?: '—' }}</td>
                                        <td class="px-3 py-1.5">
                                            @if ($m->status === 'error')
                                                <span class="px-1.5 py-0.5 rounded bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300" title="{{ $m->last_error }}">✗ hata</span>
                                            @elseif ($m->status === 'pending')
                                                <span class="px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">⏳ bekliyor</span>
                                            @else
                                                <span class="px-1.5 py-0.5 rounded bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">{{ $m->status ?: '—' }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($mapNoBayi > $notInBayi->count())
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                            İlk {{ $notInBayi->count() }} kayıt gösteriliyor (toplam {{ number_format($mapNoBayi) }}).
                        </p>
                    @endif
                @endif
            </div>

            {{-- 7-day chart (CSS bar chart, no JS dep) --}}
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Son 7 Gün</h3>
                    <div class="flex gap-3 text-xs">
                        <span class="flex items-center gap-1">
                            <span class="inline-block w-3 h-3 rounded-sm bg-blue-500"></span>
                            <span class="text-gray-600 dark:text-gray-400">Sipariş</span>
                        </span>
                        <span class="flex items-center gap-1">
                            <span class="inline-block w-3 h-3 rounded-sm bg-emerald-500"></span>
                            <span class="text-gray-600 dark:text-gray-400">Ürün</span>
                        </span>
                    </div>
                </div>
                <div class="flex items-end gap-3 h-48">
                    @foreach ($days as $day)
                        <div class="flex-1 flex flex-col items-center gap-1">
                            <div class="flex items-end gap-1 flex-1 w-full justify-center">
                                <div class="w-3 bg-blue-500 rounded-t"
                                     style="height: {{ $maxBar > 0 ? ($day['orders'] / $maxBar * 100) : 0 }}%"
                                     title="{{ $day['orders'] }} sipariş"></div>
                                <div class="w-3 bg-emerald-500 rounded-t"
                                     style="height: {{ $maxBar > 0 ? ($day['products'] / $maxBar * 100) : 0 }}%"
                                     title="{{ $day['products'] }} ürün"></div>
                            </div>
                            <div class="text-xs text-gray-500 mt-1">{{ $day['label'] }}</div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                {{-- Recent failures --}}
                <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                    <div class="flex justify-between items-center mb-3">
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Son Başarısız Aktarımlar</h3>
                        <a href="{{ route('loglar') }}?status=error" class="text-xs text-blue-600 hover:underline">Hepsini Gör →</a>
                    </div>
                    @if ($recentFailed->isEmpty())
                        <div class="text-sm text-gray-500 italic">Başarısız aktarım yok 🎉</div>
                    @else
                        <ul class="divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                            @foreach ($recentFailed as $f)
                                <li class="py-2">
                                    <div class="font-mono text-xs text-gray-900 dark:text-gray-200">Bayi: {{ $f->bayi_order_id }}</div>
                                    <div class="text-xs text-red-600 dark:text-red-400 mt-0.5">{{ \Illuminate\Support\Str::limit($f->last_error, 100) }}</div>
                                    <div class="text-xs text-gray-500 mt-0.5">{{ $f->updated_at?->diffForHumans() }} · {{ $f->retry_count }} deneme</div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                {{-- Recent jobs --}}
                <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                    <div class="flex justify-between items-center mb-3">
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Son Çalışan İşler</h3>
                        <a href="{{ route('loglar') }}" class="text-xs text-blue-600 hover:underline">Tüm Loglar →</a>
                    </div>
                    @if ($recentJobs->isEmpty())
                        <div class="text-sm text-gray-500 italic">Henüz sync çalışmadı.</div>
                    @else
                        <ul class="divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                            @foreach ($recentJobs as $j)
                                <li class="py-2 flex items-center justify-between">
                                    <div>
                                        <div class="text-xs font-mono text-gray-900 dark:text-gray-200">#{{ $j->id }} · {{ $j->type }}</div>
                                        <div class="text-xs text-gray-500 mt-0.5">
                                            {{ $j->started_at?->diffForHumans() }} ·
                                            {{ $j->success_count }}/{{ $j->total }} başarılı
                                            @if ($j->error_count > 0)
                                                · <span class="text-red-600">{{ $j->error_count }} hata</span>
                                            @endif
                                        </div>
                                    </div>
                                    <span class="px-2 py-0.5 text-xs rounded
                                        {{ $j->status === 'completed' ? 'bg-green-100 text-green-800' : '' }}
                                        {{ $j->status === 'failed' ? 'bg-red-100 text-red-800' : '' }}
                                        {{ $j->status === 'running' ? 'bg-yellow-100 text-yellow-800' : '' }}">
                                        {{ $j->status }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
