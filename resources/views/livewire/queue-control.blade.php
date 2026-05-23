<div x-data="{ open: false }" class="relative" wire:poll.5s="refreshCounts">
    {{-- Tetik butonu (chip) — 3 durum: idle (gri) / aktif (amber) / stop sinyali yollandı (kırmızı) --}}
    <button type="button" @click="open = !open"
            class="inline-flex items-center gap-2 px-3 py-1.5 rounded-md text-xs font-medium hover:opacity-90 transition
                @if ($stopRequested) bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200 ring-1 ring-red-400/50
                @elseif (($pendingJobs + $runningSyncJobs) > 0) bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200 ring-1 ring-amber-400/50
                @else bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300
                @endif">
        @if ($stopRequested)
            <span class="relative flex h-2 w-2">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2 w-2 bg-red-500"></span>
            </span>
            <span>⛔ Durduruluyor...</span>
        @elseif (($pendingJobs + $runningSyncJobs) > 0)
            <span class="relative flex h-2 w-2">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2 w-2 bg-amber-500"></span>
            </span>
            <span>Kuyruk: {{ $pendingJobs }} bekleyen · {{ $runningSyncJobs }} çalışan</span>
        @else
            <span class="inline-flex rounded-full h-2 w-2 bg-gray-400"></span>
            <span>Kuyruk: boş</span>
        @endif
    </button>

    {{-- Dropdown --}}
    <div x-show="open" @click.outside="open = false" x-cloak
         x-transition.opacity
         class="absolute right-0 mt-2 w-96 bg-white dark:bg-gray-800 shadow-lg rounded-md ring-1 ring-black/5 dark:ring-white/10 p-4 z-50">
        <div class="text-sm text-gray-700 dark:text-gray-200">
            <p class="font-medium mb-2">Kuyruk Denetimi</p>
            <ul class="text-xs text-gray-600 dark:text-gray-400 space-y-1 mb-3">
                <li>• <strong>{{ $pendingJobs }}</strong> bekleyen iş (jobs tablosunda)</li>
                <li>• <strong>{{ $runningSyncJobs }}</strong> çalışan sync işi</li>
                @if ($stopRequested)
                    <li class="text-red-600 dark:text-red-400 font-medium">• ⛔ Durdurma sinyali aktif — job kontrol noktasında çıkacak</li>
                @endif
            </ul>

            @if ($stopRequested)
                <div class="mb-3 p-2 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 rounded text-xs text-red-800 dark:text-red-200">
                    <strong>Durdurma sinyali aktif.</strong>
                    Çalışan job her ürün/sipariş arasında bu flag'i kontrol eder ve nazikçe çıkar.
                    Loglar sekmesinden "Manuel durduruldu" mesajını gördüğünde duruş tamamlanmış olur.
                </div>
                <button wire:click="clearStopFlag"
                        @click="open = false"
                        class="w-full px-3 py-2 bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-gray-200 text-sm rounded hover:bg-gray-300 dark:hover:bg-gray-600">
                    🔓 Stop Flag'i Temizle (yeni sync başlatmadan önce)
                </button>
            @else
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-3 leading-relaxed">
                    "Şimdi Durdur" hem bekleyen işleri siler hem de çalışan worker'a durdurma flag'i yollar.
                    Worker mevcut işin <strong>bir sonraki ürün/sipariş arasında</strong> bu flag'i görür ve nazikçe çıkar.
                </p>
                <button wire:click="stopAll"
                        wire:confirm="Tüm kuyruğu durduracaksın, devam edilsin mi?"
                        @click="open = false"
                        class="w-full px-3 py-2 bg-red-600 text-white text-sm rounded hover:bg-red-700">
                    ⛔ Şimdi Durdur
                </button>
            @endif

            @if ($statusMsg)
                <div class="mt-3 text-xs text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-900/30 p-2 rounded">
                    {{ $statusMsg }}
                </div>
            @endif
        </div>
    </div>
</div>
