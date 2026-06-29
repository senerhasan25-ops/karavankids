<div class="p-6 max-w-7xl mx-auto">
    <h1 class="text-2xl font-bold mb-4">Manuel Ürün Aktarımı</h1>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
        Ana mağazadan ürün listele, seç, parametre belirle, bayiye aktar — hepsi tek sayfada.
        Stok kodunu boş bırakırsan tüm ürünleri sayfa sayfa listeler. Tek değer "içerir" (LIKE),
        virgüllü çoklu birebir arama yapar.
    </p>

    {{-- ARAMA / LISTELE --}}
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4 mb-4">
        <label class="block text-sm font-medium mb-1">Stok Kodu (opsiyonel)</label>
        <input type="text" wire:model="query"
               wire:keydown.enter="listele"
               placeholder="Boş bırak → tümünü listele. Örn: 168814  veya  168814, 168805"
               class="w-full px-3 py-2 border rounded-md dark:bg-gray-900 dark:border-gray-700 mb-3"
               style="display:block; width:100%;">

        {{-- Listele butonu — inline style ile Tailwind compile edilmemis ortamda da gorunur olsun --}}
        <button type="button"
                wire:click="listele"
                wire:loading.attr="disabled"
                wire:target="listele"
                class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-md disabled:opacity-50"
                style="display:inline-block; padding:0.5rem 1.5rem; background:#2563eb; color:#fff; font-weight:600; border:none; border-radius:0.375rem; cursor:pointer;">
            <span wire:loading.remove wire:target="listele">Listele</span>
            <span wire:loading wire:target="listele">Yükleniyor…</span>
        </button>

        @if($error)
            <div class="mt-3 px-4 py-2 bg-red-100 dark:bg-red-900/30 border border-red-300 dark:border-red-700 rounded text-sm text-red-800 dark:text-red-200">
                {{ $error }}
            </div>
        @endif
        @if($status)
            <div class="mt-3 px-4 py-2 bg-green-100 dark:bg-green-900/30 border border-green-300 dark:border-green-700 rounded text-sm text-green-800 dark:text-green-200">
                {{ $status }}
            </div>
        @endif
        @if($hasSearched && $resultCount > 0)
            <div class="mt-3 flex items-center justify-between text-sm text-gray-600 dark:text-gray-400">
                <span>Sayfa <strong>{{ $page }}</strong> — bu sayfada {{ $resultCount }} satır (100 ürün/sayfa).</span>
                @if($query === '')
                    <div class="flex gap-2">
                        <button wire:click="oncekiSayfa" @disabled($page <= 1) class="px-3 py-1 rounded bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 disabled:opacity-50">← Önceki</button>
                        <button wire:click="sonrakiSayfa" @disabled(! $hasMore) class="px-3 py-1 rounded bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 disabled:opacity-50">Sonraki →</button>
                    </div>
                @endif
            </div>
        @endif
    </div>

    {{-- PARAMETRELER — her zaman görünür, listeden önce de ayarlanabilir --}}
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4 mb-4">
        <div class="flex items-center justify-between mb-1">
            <h2 class="font-semibold">Güncellenecek Parametreler</h2>
            <div class="flex gap-2 text-xs">
                <button wire:click="tumunuSec" class="px-2 py-1 rounded bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600">Tümü</button>
                <button wire:click="hicbirini" class="px-2 py-1 rounded bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600">Hiçbiri</button>
            </div>
        </div>
        <p class="text-xs text-blue-600 dark:text-blue-400 mb-3">
            💾 Seçimler otomatik kaydedilir — sayfa yenilenince ve otomatik ürün sync'inde bu ayarlar kullanılır.
        </p>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2 text-sm">
            @foreach([
                'urun_adi'         => 'Ürün Adı',
                'aciklama'         => 'Açıklama',
                'on_yazi'          => 'Ön Yazı',
                'kategori'         => 'Kategori',
                'marka'            => 'Marka',
                'tedarikci'        => 'Tedarikçi',
                'satis_fiyati'     => 'Satış Fiyatı',
                'indirimli_fiyat'  => 'İndirimli Fiyat',
                'stok_adedi'       => 'Stok Adedi',
                'eksi_stok_adedi'  => 'Eksi Stok Adedi',
                'kdv_dahil'        => 'KDV Dahil',
                'kdv_orani'        => 'KDV Oranı',
                'stok_kodu'        => 'Stok Kodu',
                'barkod'           => 'Barkod',
                'seo'              => 'SEO',
                'resimler'         => 'Resimler',
                'aktif'            => 'Aktiflik',
                'vitrin'           => 'Vitrin',
                'yeni_urun'        => 'Yeni Ürün',
                'firsat_urunu'     => 'Fırsat Ürünü',
            ] as $key => $label)
                <label class="flex items-center gap-2 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/50 px-2 py-1 rounded">
                    <input type="checkbox" wire:model.live="fields.{{ $key }}" class="rounded">
                    <span>{{ $label }}</span>
                </label>
            @endforeach
        </div>

        {{-- Uye Tipi Fiyatlari 1-20 alt panel --}}
        <div class="mt-4 pt-3 border-t dark:border-gray-700">
            <div class="flex items-center justify-between mb-2">
                <h3 class="text-sm font-semibold">Üye Tipi Fiyatları (1–20)</h3>
                <label class="flex items-center gap-2 cursor-pointer text-xs text-gray-600 dark:text-gray-400">
                    <input type="checkbox" wire:model.live="fields.uye_tipi_fiyat" class="rounded">
                    <span>Hepsini birden seç</span>
                </label>
            </div>
            <div class="grid grid-cols-4 sm:grid-cols-5 md:grid-cols-10 gap-1 text-xs">
                @for($i = 1; $i <= 20; $i++)
                    <label class="flex items-center gap-1 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/50 px-2 py-1 rounded">
                        <input type="checkbox" wire:model.live="uyeTipi.{{ $i }}" class="rounded">
                        <span>Fiyat {{ $i }}</span>
                    </label>
                @endfor
            </div>
        </div>
    </div>

    {{-- URUN LISTESI --}}
    @if(! empty($products))
        {{-- Tablo içi filtreler --}}
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-3 mb-3 flex flex-wrap gap-2 items-end">
            <div class="flex-1 min-w-[140px]">
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Stok Kodu</label>
                <input type="text"
                       wire:model.live.debounce.300ms="filterStokKodu"
                       placeholder="İçerik ara…"
                       class="w-full px-2 py-1.5 text-sm border rounded dark:bg-gray-900 dark:border-gray-700">
            </div>
            <div class="flex-1 min-w-[140px]">
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Barkod</label>
                <input type="text"
                       wire:model.live.debounce.300ms="filterBarkod"
                       placeholder="İçerik ara…"
                       class="w-full px-2 py-1.5 text-sm border rounded dark:bg-gray-900 dark:border-gray-700">
            </div>
            <div class="flex-[2] min-w-[200px]">
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Ürün Adı</label>
                <input type="text"
                       wire:model.live.debounce.300ms="filterUrunAdi"
                       placeholder="İçerik ara…"
                       class="w-full px-2 py-1.5 text-sm border rounded dark:bg-gray-900 dark:border-gray-700">
            </div>
            @if($filterStokKodu !== '' || $filterBarkod !== '' || $filterUrunAdi !== '')
                <div class="flex items-end pb-0.5">
                    <button wire:click="filterTemizle"
                            class="px-3 py-1.5 text-sm bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 rounded"
                            title="Filtreleri temizle">
                        ✕ Temizle
                    </button>
                </div>
            @endif
            @php $gorünen = count($this->displayedProducts); $toplam = count($products); @endphp
            <div class="ml-auto self-end text-xs text-gray-500 dark:text-gray-400 pb-0.5 whitespace-nowrap">
                @if($gorünen < $toplam)
                    <span class="font-semibold text-blue-600 dark:text-blue-400">{{ $gorünen }}</span> / {{ $toplam }} satır
                @else
                    {{ $toplam }} satır
                @endif
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden mb-4">
            <div class="px-4 py-3 border-b dark:border-gray-700 flex items-center gap-3">
                <input type="checkbox" wire:model.live="selectAll" id="selAll" class="rounded">
                <label for="selAll" class="text-sm cursor-pointer">Hepsini Seç</label>
                @if(count($selected) > 0)
                    <span class="ml-auto text-sm text-blue-600 dark:text-blue-400 font-medium">{{ count($selected) }} satır seçili</span>
                @endif
            </div>

            <div class="overflow-x-auto max-h-[60vh]">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900 text-left sticky top-0">
                        <tr>
                            <th class="px-3 py-2 w-10"></th>
                            <th class="px-3 py-2">Ürün Kart ID</th>
                            <th class="px-3 py-2">Ürün ID</th>
                            <th class="px-3 py-2">Stok Kodu</th>
                            <th class="px-3 py-2">Ürün Adı</th>
                            <th class="px-3 py-2">Barkod</th>
                            <th class="px-3 py-2 text-right">Stok</th>
                            <th class="px-3 py-2 text-right">Satış</th>
                            <th class="px-3 py-2 text-right">İndirimli</th>
                            <th class="px-3 py-2">Aktif</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse($this->displayedProducts as $row)
                            @php
                                $isSel = in_array((string) $row['variant_id'], $selected, true);
                            @endphp
                            <tr class="cursor-pointer hover:bg-blue-50 dark:hover:bg-blue-900/20 {{ $isSel ? 'bg-blue-100 dark:bg-blue-900/30' : '' }}"
                                wire:click="$toggle('selected.{{ $row['variant_id'] }}')">
                                <td class="px-3 py-2" wire:click.stop>
                                    <input type="checkbox" wire:model.live="selected" value="{{ $row['variant_id'] }}" class="rounded">
                                </td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $row['urun_karti_id'] }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $row['variant_id'] }}</td>
                                <td class="px-3 py-2 font-mono">{{ $row['stok_kodu'] }}</td>
                                <td class="px-3 py-2 max-w-md truncate" title="{{ $row['urun_adi'] }}">{{ $row['urun_adi'] }}</td>
                                <td class="px-3 py-2 font-mono text-xs">{{ $row['barkod'] }}</td>
                                <td class="px-3 py-2 text-right">{{ $row['stok_adedi'] }}</td>
                                <td class="px-3 py-2 text-right">{{ number_format($row['satis_fiyati'], 2, ',', '.') }}</td>
                                <td class="px-3 py-2 text-right">
                                    @if($row['indirimli_fiyat'] > 0)
                                        {{ number_format($row['indirimli_fiyat'], 2, ',', '.') }}
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    @if($row['aktif'])
                                        <span class="px-2 py-0.5 text-xs rounded bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300">Aktif</span>
                                    @else
                                        <span class="px-2 py-0.5 text-xs rounded bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">Pasif</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-4 py-6 text-center text-sm text-gray-400 dark:text-gray-500">
                                    Filtre kriterlerine uyan ürün yok.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- AKTAR BUTONLARI --}}
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4 mb-4">
            {{-- TÜM KATALOG — sayfa sınırı olmadan tüm ürünleri aktar --}}
            <div class="mb-3 pb-3 border-b dark:border-gray-700">
                <button type="button"
                        wire:click="tumKatalogAktar" wire:loading.attr="disabled" wire:target="tumKatalogAktar"
                        wire:confirm="Ana mağazadaki TÜM ürünler (tüm sayfalar) seçili parametrelere göre bayiye aktarılacak/güncellenecek. Eşleşme tedarikçi koduyla yapılır. Devam edilsin mi?"
                        class="w-full px-5 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-md"
                        style="display:block; width:100%; padding:0.75rem 1.25rem; background:#4f46e5; color:#fff; font-weight:700; border:none; border-radius:0.375rem; cursor:pointer;"
                        title="Sayfadaki 100 ürünle sınırlı DEĞİL — ana mağazadaki tüm kataloğu sunucu tarafında sayfa sayfa işler. Eşleşme yalnızca tedarikçi kodu ile.">
                    <span wire:loading.remove wire:target="tumKatalogAktar">📦 TÜM ÜRÜNLERİ AKTAR (tüm sayfalar — seçili parametrelerle)</span>
                    <span wire:loading wire:target="tumKatalogAktar">Tüm katalog kuyruğa alınıyor…</span>
                </button>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-2 leading-relaxed">
                    Aşağıdaki "Seçilenleri Aktar" yalnızca bu sayfadaki seçili satırları aktarır.
                    Tüm kataloğu (tüm sayfalar) aktarmak için bu butonu kullan — 100 ürün sınırı yoktur.
                </p>
            </div>
            <div class="flex flex-wrap justify-between gap-2">
                {{-- Secimden bagimsiz: listedeki tum urunleri tarayip yeni olanlari aktar --}}
                <button type="button"
                        wire:click="yeniUrunleriAktar" wire:loading.attr="disabled" wire:target="yeniUrunleriAktar"
                        class="px-5 py-2 bg-purple-600 hover:bg-purple-700 text-white font-medium rounded-md"
                        style="display:inline-block; padding:0.5rem 1.25rem; background:#9333ea; color:#fff; font-weight:600; border:none; border-radius:0.375rem; cursor:pointer;"
                        title="Listede gözüken ürünleri bayide tara, bayide olmayanları yeni ürün olarak ekle. Seçim gerekmez.">
                    <span wire:loading.remove wire:target="yeniUrunleriAktar">+ Sadece Yeni Ürünleri Aktar ({{ count($products) }})</span>
                    <span wire:loading wire:target="yeniUrunleriAktar">Yeniler aktarılıyor…</span>
                </button>

                <div class="flex flex-wrap gap-2">
                    {{-- Hizli yol: secili urunlerin sadece stok + fiyat update --}}
                    <button type="button"
                            wire:click="stokFiyatGuncelle" wire:loading.attr="disabled" wire:target="stokFiyatGuncelle"
                            @disabled(count($selected) === 0)
                            class="px-5 py-2 bg-orange-500 hover:bg-orange-600 text-white font-medium rounded-md disabled:opacity-50"
                            style="display:inline-block; padding:0.5rem 1.25rem; background:#f97316; color:#fff; font-weight:600; border:none; border-radius:0.375rem; cursor:pointer;"
                            title="Sadece stok ve fiyat alanlarını günceller. Bayide olmayan ürünler atlanır.">
                        <span wire:loading.remove wire:target="stokFiyatGuncelle">Stok/Fiyat Güncelle ({{ count($selected) }})</span>
                        <span wire:loading wire:target="stokFiyatGuncelle">Güncelleniyor…</span>
                    </button>

                    {{-- Tam aktarim: yukaridaki parametre paneline gore --}}
                    <button type="button"
                            wire:click="aktar" wire:loading.attr="disabled" wire:target="aktar"
                            @disabled(count($selected) === 0)
                            class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-md disabled:opacity-50"
                            style="display:inline-block; padding:0.5rem 1.5rem; background:#16a34a; color:#fff; font-weight:600; border:none; border-radius:0.375rem; cursor:pointer;">
                        <span wire:loading.remove wire:target="aktar">Seçilenleri Aktar ({{ count($selected) }})</span>
                        <span wire:loading wire:target="aktar">Aktarılıyor…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- SONUC TABLOSU --}}
    @if(! empty($results))
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
            <div class="px-4 py-3 border-b dark:border-gray-700 font-semibold">Aktarım Sonuçları</div>
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-900 text-left">
                    <tr>
                        <th class="px-3 py-2">Stok Kodu</th>
                        <th class="px-3 py-2">Ürün Adı</th>
                        <th class="px-3 py-2">Durum</th>
                        <th class="px-3 py-2">Mesaj</th>
                    </tr>
                </thead>
                <tbody class="divide-y dark:divide-gray-700">
                    @foreach($results as $r)
                        <tr>
                            <td class="px-3 py-2 font-mono text-xs">{{ $r['stok_kodu'] }}</td>
                            <td class="px-3 py-2 max-w-md truncate" title="{{ $r['urun_adi'] }}">{{ $r['urun_adi'] }}</td>
                            <td class="px-3 py-2">
                                @switch($r['durum'])
                                    @case('olusturuldu')
                                        <span class="px-2 py-0.5 text-xs rounded bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300">Oluşturuldu</span>
                                        @break
                                    @case('guncellendi')
                                        <span class="px-2 py-0.5 text-xs rounded bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300">Güncellendi</span>
                                        @break
                                    @case('hata')
                                        <span class="px-2 py-0.5 text-xs rounded bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300">Hata</span>
                                        @break
                                    @default
                                        <span class="px-2 py-0.5 text-xs rounded bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300">{{ $r['durum'] }}</span>
                                @endswitch
                            </td>
                            <td class="px-3 py-2 text-xs text-gray-600 dark:text-gray-400 max-w-xl truncate" title="{{ $r['mesaj'] }}">{{ $r['mesaj'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
