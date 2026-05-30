<?php

/**
 * SyncNewProductsJob'u senkron çalıştır (eksik=pending ürünleri açma testi).
 * Kullanım: php scripts/debug/debug-run-newproducts.php
 */

require __DIR__.'/../../vendor/autoload.php';

use App\Jobs\SyncNewProductsJob;
use App\Models\ProductMapping;
use App\Models\SyncJob;
use Illuminate\Contracts\Console\Kernel;

$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

echo 'ÖNCE pending (bayide yok): '.ProductMapping::where('status', 'pending')->whereNull('bayi_product_id')->count()."\n\n";

$t = microtime(true);
SyncNewProductsJob::dispatchSync();
$dt = round(microtime(true) - $t, 1);

$job = SyncJob::where('type', 'product_create')->latest('id')->first();
echo "JOB #{$job->id}: status={$job->status} total={$job->total} success={$job->success_count} error={$job->error_count} ({$dt}sn)\n";
echo '  last_error: '.($job->last_error ?: '—')."\n\n";

echo 'SONRA pending (bayide yok): '.ProductMapping::where('status', 'pending')->whereNull('bayi_product_id')->count()."\n";
echo 'synced toplam: '.ProductMapping::where('status', 'synced')->count()."\n";
