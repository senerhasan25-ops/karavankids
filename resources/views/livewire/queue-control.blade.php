<div x-data="{ open: false }" class="relative" wire:poll.5s="refreshCounts">
    @php
        $pendingCount = count($pendingJobs);
        $runningCount = count($runningJobs);
        $total = $pendingCount + $runningCount;
    @endphp

    {{-- Tetik butonu (chip) — 3 durum: idle (gri) / aktif (amber) / global stop (kırmızı) --}}
    <button type="button" @click="open = !open"
            class="inline-flex items-center gap-2 px-3 py-1.5 rounded-md text-xs font-medium hover:opacity-90 transition
                @if ($stopRequested) bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200 ring-1 ring-red-400/50
                @elseif ($total > 0) bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200 ring-1 ring-amber-400/50
                @else bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300
                @endif">
        @if ($stopRequested)
            <span class="relative flex h-2 w-2">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2 w-2 bg-red-500"></span>
            </span>
            <span>⛔ Global durdur aktif</span>
        @elseif ($total > 0)
            <span class="relative flex h-2 w-2">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2 w-2 bg-amber-500"></span>
            </span>
            <span>Kuyruk: {{ $pendingCount }} bekleyen · {{ $runningCount }} çalışan</span>
        @else
            <span class="inline-flex rounded-full h-2 w-2 bg-gray-400"></span>
            <span>Kuyruk: boş</span>
        @endif
    </button>

    {{-- Dropdown --}}
    <div x-show="open" @click.outside="open = false" x-cloak
         x-transition.opacity
         class="absolute right-0 mt-2 w-[28rem] max-h-[80vh] overflow-y-auto bg-white dark:bg-gray-800 shadow-lg rounded-md ring-1 ring-black/5 dark:ring-white/10 p-4 z-50">
        <div class="text-sm text-gray-700 dark:text-gray-200">
            <p class="font-medium mb-3">Kuyruk Denetimi</p>

            {{-- ÇALIŞAN İŞLER --}}
            <div class="mb-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">
                    Çalışan ({{ $runningCount }})
                </p>
                @if ($runningCount === 0)
                    <p class="text-xs text-gray-400 italic">Şu an çalışan sync yok.</p>
                @else
                    <ul class="space-y-1">
                        @foreach ($runningJobs as $r)
                            <li class="flex items-center justify-between gap-2 text-xs bg-amber-50 dark:bg-amber-900/20 px-2 py-1.5 rounded">
                                <div class="flex-1 min-w-0">
                                    <div class="font-medium truncate">
                                        #{{ $r['id'] }} · {{ $r['type'] }}
                                        @if ($r['stop_pending'])
                                            <span class="text-red-600 dark:text-red-400">(durduruluyor…)</span>
                                        @endif
                                    </div>
                                    <div class="text-gray-500 dark:text-gray-400">
                                        {{ $r['started_at'] }} · {{ $r['success'] }}✓ / {{ $r['error'] }}✗ / {{ $r['total'] }} toplam
                                    </div>
                                </div>
                                <button wire:click="cancelRunning({{ $r['id'] }})"
                                        wire:loading.attr="disabled"
                                        class="shrink-0 px-2 py-1 bg-red-600 hover:bg-red-700 text-white text-xs rounded">
                                    ⛔ Durdur
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- BEKLEYEN İŞLER --}}
            <div class="mb-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">
                    Bekleyen ({{ $pendingCount }})
                </p>
                @if ($pendingCount === 0)
                    <p class="text-xs text-gray-400 italic">Bekleyen iş yok.</p>
                @else
                    <ul class="space-y-1">
                        @foreach ($pendingJobs as $p)
                            <li class="flex items-center justify-between gap-2 text-xs bg-gray-50 dark:bg-gray-700/50 px-2 py-1.5 rounded">
                                <div class="flex-1 min-w-0">
                                    <div class="font-medium truncate">#{{ $p['id'] }} · {{ $p['label'] }}</div>
                                    <div class="text-gray-500 dark:text-gray-400">
                                        kuyruk: {{ $p['queue'] }} · {{ $p['attempts'] }} deneme
                                    </div>
                                </div>
                                <button wire:click="cancelPending({{ $p['id'] }})"
                                        wire:loading.attr="disabled"
                                        class="shrink-0 px-2 py-1 bg-gray-300 hover:bg-gray-400 dark:bg-gray-600 dark:hover:bg-gray-500 text-gray-800 dark:text-gray-100 text-xs rounded">
                                    ✕ Sil
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- AUTO-SYNC TOGGLE --}}
            <div class="border-t border-gray-200 dark:border-gray-700 pt-3 mb-3">
                <button wire:click="toggleAutoSync"
                        class="w-full flex items-center justify-between gap-2 px-3 py-2 rounded text-xs
                            @if ($autoSyncEnabled) bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-200 hover:bg-green-200
                            @else bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600
                            @endif">
                    <span class="font-medium">Otomatik Sync</span>
                    <span>
                        @if ($autoSyncEnabled)
                            🟢 AÇIK (scheduler aktif)
                        @else
                            ⚫ KAPALI
                        @endif
                    </span>
                </button>
                @if ($autoSyncEnabled)
                    <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-1 leading-snug">
                        Tek tek "Durdur" yetmez — scheduler 1 dk sonra yenisini dispatch eder.
                        Kalıcı durdurmak için bunu KAPATMAYI unutma.
                    </p>
                @endif
            </div>

            {{-- TOPLU AKSİYONLAR --}}
            <div class="border-t border-gray-200 dark:border-gray-700 pt-3 space-y-2">
                @if ($stopRequested)
                    <div class="p-2 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 rounded text-xs text-red-800 dark:text-red-200">
                        <strong>Global stop flag aktif.</strong>
                        Yeni başlatılacak job'lar bile hemen çıkar. Sıfırlamadan yeni sync başlatma!
                    </div>
                    <button wire:click="clearStopFlag"
                            class="w-full px-3 py-2 bg-gray-200 dark:bg-gray-700 text-gray-800 dark:text-gray-200 text-sm rounded hover:bg-gray-300 dark:hover:bg-gray-600">
                        🔓 Global Flag'i Temizle
                    </button>
                @elseif ($total > 0)
                    <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed">
                        "Hepsini Durdur" tek seferde tüm bekleyenleri siler + çalışanlara global stop yollar.
                        Worker süreci kapanmaz; mevcut SOAP biter bitmez çıkar (5-30 sn).
                    </p>
                    <button type="button"
                            x-on:click="if (confirm('Tüm kuyruğu durduracaksın, devam edilsin mi?')) { $wire.stopAll() }"
                            class="w-full px-3 py-2 bg-red-600 text-white text-sm rounded hover:bg-red-700">
                        ⛔ Hepsini Durdur
                    </button>
                @endif

                @if ($statusMsg)
                    <div class="text-xs text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-900/30 p-2 rounded">
                        {{ $statusMsg }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
