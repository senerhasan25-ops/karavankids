<div x-data="{ open: false }" class="relative" wire:poll.10s="refreshCounts">
    {{-- Tetik butonu (chip) --}}
    <button type="button" @click="open = !open"
            class="inline-flex items-center gap-2 px-3 py-1.5 rounded-md text-xs font-medium
                   {{ ($pendingJobs + $runningSyncJobs) > 0
                       ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200 ring-1 ring-amber-400/50'
                       : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300' }}
                   hover:opacity-90 transition">
        @if (($pendingJobs + $runningSyncJobs) > 0)
            <span class="relative flex h-2 w-2">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2 w-2 bg-amber-500"></span>
            </span>
        @else
            <span class="inline-flex rounded-full h-2 w-2 bg-gray-400"></span>
        @endif
        <span>Kuyruk: {{ $pendingJobs }} bekleyen · {{ $runningSyncJobs }} çalışan</span>
    </button>

    {{-- Dropdown --}}
    <div x-show="open" @click.outside="open = false" x-cloak
         x-transition.opacity
         class="absolute right-0 mt-2 w-80 bg-white dark:bg-gray-800 shadow-lg rounded-md ring-1 ring-black/5 dark:ring-white/10 p-4 z-50">
        <div class="text-sm text-gray-700 dark:text-gray-200">
            <p class="font-medium mb-2">Kuyruk Denetimi</p>
            <ul class="text-xs text-gray-600 dark:text-gray-400 space-y-1 mb-3">
                <li>• <strong>{{ $pendingJobs }}</strong> bekleyen iş (jobs tablosunda)</li>
                <li>• <strong>{{ $runningSyncJobs }}</strong> çalışan sync işi (sync_jobs)</li>
            </ul>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3 leading-relaxed">
                "Şimdi Durdur" tüm bekleyen işleri siler, çalışan worker'a kapan sinyali yollar
                ve çalışan sync işlerini iptal eder. Worker'ın cmd penceresini ayrıca kapatman
                gerekebilir.
            </p>
            <button wire:click="stopAll"
                    wire:confirm="Tüm kuyruğu durduracaksın, devam edilsin mi?"
                    @click="open = false"
                    class="w-full px-3 py-2 bg-red-600 text-white text-sm rounded hover:bg-red-700">
                ⛔ Şimdi Durdur
            </button>
            @if ($statusMsg)
                <div class="mt-3 text-xs text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-900/30 p-2 rounded">
                    {{ $statusMsg }}
                </div>
            @endif
        </div>
    </div>
</div>
