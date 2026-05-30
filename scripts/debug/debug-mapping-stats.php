<?php

/**
 * Sayım tutarsızlığı tanısı: 3138 "başarılı" ama bayide 2495 ürün.
 *
 * ProductMapping istatistikleri + distinct bayi_product_id sayısı.
 * Eğer distinct bayi_product_id ≈ 2495 ise: birden çok ana stok_kodu AYNI bayi
 * kartına düşüyor (barkod/merge) → "başarılı" sayısı kart sayısından fazla.
 *
 * Kullanım: php scripts/debug/debug-mapping-stats.php
 */

require __DIR__.'/../../vendor/autoload.php';

use App\Models\ProductMapping;
use App\Models\SyncJob;
use App\Models\SyncLog;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

echo "=== ProductMapping istatistikleri ===\n";
echo 'Toplam satır (varyasyon)        : '.ProductMapping::count()."\n";
echo 'Distinct stok_kodu              : '.ProductMapping::distinct('stok_kodu')->count('stok_kodu')."\n";
echo 'bayi_product_id DOLU satır      : '.ProductMapping::whereNotNull('bayi_product_id')->count()."\n";
echo 'Distinct bayi_product_id (kart) : '.ProductMapping::whereNotNull('bayi_product_id')->distinct('bayi_product_id')->count('bayi_product_id')."\n";
echo 'Distinct ana_product_id (kart)  : '.ProductMapping::whereNotNull('ana_product_id')->distinct('ana_product_id')->count('ana_product_id')."\n";
echo 'status=error satır              : '.ProductMapping::where('status', 'error')->count()."\n";
echo 'bayi_product_id NULL satır      : '.ProductMapping::whereNull('bayi_product_id')->count()."\n\n";

echo "=== Aynı bayi_product_id'ye düşen birden çok ana ürün (ilk 15) ===\n";
$dupes = DB::table('product_mappings')
    ->select('bayi_product_id', DB::raw('COUNT(DISTINCT ana_product_id) as ana_kart_sayisi'), DB::raw('COUNT(*) as satir'))
    ->whereNotNull('bayi_product_id')
    ->groupBy('bayi_product_id')
    ->havingRaw('COUNT(DISTINCT ana_product_id) > 1')
    ->orderByDesc('ana_kart_sayisi')
    ->limit(15)
    ->get();
if ($dupes->isEmpty()) {
    echo "  (yok — her bayi kartına tek ana kart düşüyor)\n";
} else {
    foreach ($dupes as $d) {
        echo "  bayi #{$d->bayi_product_id} ← {$d->ana_kart_sayisi} farklı ana kart ({$d->satir} satır)\n";
    }
    $totalDupeCards = DB::table('product_mappings')
        ->select('bayi_product_id')
        ->whereNotNull('bayi_product_id')
        ->groupBy('bayi_product_id')
        ->havingRaw('COUNT(DISTINCT ana_product_id) > 1')
        ->get()->count();
    echo "  ... toplam {$totalDupeCards} bayi kartı birden çok ana karta bağlı.\n";
}

echo "\n=== Son product_create job'u ===\n";
$job = SyncJob::where('type', 'product_create')->latest('id')->first();
if ($job) {
    echo "Job #{$job->id}: total={$job->total} success={$job->success_count} error={$job->error_count} status={$job->status}\n";
    $byStatus = SyncLog::where('job_id', $job->id)->select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status');
    foreach ($byStatus as $s => $c) {
        echo "  log status={$s}: {$c}\n";
    }
    // success log'larında distinct stok_kodu
    $distinctSuccess = SyncLog::where('job_id', $job->id)->where('status', 'success')->distinct('stok_kodu')->count('stok_kodu');
    echo "  success log distinct stok_kodu: {$distinctSuccess}\n";
}
