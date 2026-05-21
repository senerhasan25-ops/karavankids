<div>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">Dashboard</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">
            {{-- Top summary cards --}}
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
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

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                {{-- Recent failures --}}
                <div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
                    <div class="flex justify-between items-center mb-3">
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">Son Başarısız Aktarımlar</h3>
                        <a href="{{ route('siparisler') }}?statusFilter=failed" class="text-xs text-blue-600 hover:underline">Hepsini Gör →</a>
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
