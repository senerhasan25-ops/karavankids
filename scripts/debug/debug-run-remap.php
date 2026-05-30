<?php

/**
 * FullRemapProductsJob'u senkron çalıştır ve sonucu raporla.
 * Kullanım: php scripts/debug/debug-run-remap.php
 */

require __DIR__.'/../../vendor/autoload.php';

use App\Jobs\FullRemapProductsJob;
use App\Models\ProductMapping;
use App\Models\SyncJob;
use Illuminate\Contracts\Console\Kernel;

$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

echo "ÖNCE — ProductMapping:\n";
echo '  toplam='.ProductMapping::count()
    .' bayi_id_dolu='.ProductMapping::whereNotNull('bayi_product_id')->count()
    .' distinct_bayi='.ProductMapping::whereNotNull('bayi_product_id')->distinct('bayi_product_id')->count('bayi_product_id')."\n\n";

$t = microtime(true);
FullRemapProductsJob::dispatchSync();
$dt = round(microtime(true) - $t, 1);

$job = SyncJob::where('type', 'product_remap')->latest('id')->first();
echo "JOB #{$job->id}: status={$job->status} total={$job->total} eşleşen={$job->success_count} eşleşmeyen={$job->error_count} ({$dt}sn)\n";
echo '  last_error: '.($job->last_error ?: '—')."\n\n";

echo "SONRA — ProductMapping:\n";
echo '  toplam='.ProductMapping::count()
    .' bayi_id_dolu='.ProductMapping::whereNotNull('bayi_product_id')->count()
    .' distinct_bayi='.ProductMapping::whereNotNull('bayi_product_id')->distinct('bayi_product_id')->count('bayi_product_id')."\n";
echo '  status=pending (bayide yok)='.ProductMapping::where('status', 'pending')->count()."\n";
echo '  status=synced='.ProductMapping::where('status', 'synced')->count()."\n";
