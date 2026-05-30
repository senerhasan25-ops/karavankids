<?php

/**
 * TEK SEFERLİK tam katalog ürün senkronu — sentetik (SUP2026) mapping satırlarını
 * gerçek tedarikçi koduna (SUP26) yakınsatmak için. Yeni koddaki ID-refetch + dedup
 * mantığı her ürün için çalışır. Senkron (queue worker'a düşmeden) çalışır.
 *
 * since=2015-01-01 → created filtresi tüm kataloğu döndürür.
 */
require __DIR__.'/../../vendor/autoload.php';

use App\Jobs\SyncNewProductsJob;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;

$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

echo '=== Tam senkron başlıyor: '.Carbon::now()->toDateTimeString()." ===\n";

$job = new SyncNewProductsJob(
    Carbon::parse('2015-01-01')->startOfDay(),
    Carbon::now()->endOfDay(),
);
$job->handle();

echo '=== Bitti: '.Carbon::now()->toDateTimeString()." ===\n";
