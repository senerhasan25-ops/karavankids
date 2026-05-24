<div class="p-6 max-w-7xl mx-auto">
    <h1 class="text-2xl font-bold mb-4">Aktarım Parametreleri</h1>

    @if($globalError)
        <div class="mb-4 px-4 py-3 bg-red-100 dark:bg-red-900/30 border border-red-300 dark:border-red-700 rounded text-sm text-red-800 dark:text-red-200">
            {{ $globalError }}
            <a href="{{ route('urunler.listele') }}" class="ml-2 underline">← Listele sayfasına dön</a>
        </div>
    @endif

    @if(! empty($products))
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4 lg:col-span-2">
                <h2 class="font-semibold mb-3">Seçili Ürünler ({{ count($products) }})</h2>
                <div class="space-y-1 max-h-72 overflow-y-auto text-sm">
                    @foreach($products as $r)
                        <div class="flex gap-3 py-1 border-b last:border-0 dark:border-gray-700">
                            <code class="text-xs text-gray-500 w-24 shrink-0">{{ $r['stok_kodu'] }}</code>
                            <div class="flex-1 truncate" title="{{ $r['urun_adi'] }}">{{ $r['urun_adi'] }}</div>
                            <div class="text-xs text-gray-500 shrink-0">stok: {{ $r['stok_adedi'] }} • fiyat: {{ number_format($r['satis_fiyati'], 2, ',', '.') }}</div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4">
                <h2 class="font-semibold mb-3">Güncellenecek Parametreler</h2>
                <div class="flex gap-2 mb-3 text-xs">
                    <button wire:click="toggleAll(true)" class="px-2 py-1 rounded bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600">Tümünü Seç</button>
                    <button wire:click="toggleAll(false)" class="px-2 py-1 rounded bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600">Hiçbirini</button>
                </div>
                <div class="space-y-1 text-sm">
                    @foreach([
                        'urun_adi'        => 'Ürün Adı',
                        'aciklama'        => 'Açıklama',
                        'on_yazi'         => 'Ön Yazı',
                        'kategori'        => 'Kategori',
                        'marka'           => 'Marka',
                        'tedarikci'       => 'Tedarikçi',
                        'satis_fiyati'    => 'Satış Fiyatı',
                        'indirimli_fiyat' => 'İndirimli Fiyat',
                        'stok_adedi'      => 'Stok Adedi',
                        'kdv_dahil'       => 'KDV Dahil/Hariç',
                        'kdv_orani'       => 'KDV Oranı',
                        'seo'             => 'SEO (Başlık + Açıklama + Anahtar)',
                        'uye_tipi_fiyat'  => 'Üye Tipi Fiyatları (1–20)',
                        'resimler'        => 'Resimler',
                        'aktif'           => 'Aktiflik (Aktif/Pasif)',
                    ] as $key => $label)
                        <label class="flex items-center gap-2 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/50 px-2 py-1 rounded">
                            <input type="checkbox" wire:model.live="fields.{{ $key }}" class="rounded">
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-4 mb-4 flex justify-between items-center">
            <a href="{{ route('urunler.listele') }}" class="text-sm text-blue-600 hover:underline">← Yeni listeleme</a>
            <button wire:click="aktar" wire:loading.attr="disabled"
                    class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-md disabled:opacity-50">
                <span wire:loading.remove wire:target="aktar">Aktar</span>
                <span wire:loading wire:target="aktar">Aktarılıyor… ({{ count($products) }} ürün)</span>
            </button>
        </div>
    @endif

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
