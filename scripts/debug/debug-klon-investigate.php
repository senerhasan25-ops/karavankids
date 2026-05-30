<?php

/**
 * Bayide görünen KLON/CLONE/TEST ürünleri nereden geldi?
 *  1) Job #58 (son product_create) hangi stok_kodlarını oluşturdu?
 *  2) ProductMapping'de KLON/CLONE/TEST stok_kodlu kayıtlar
 *  3) Ana mağazada bu klonlar var mı?
 */

require __DIR__.'/../../vendor/autoload.php';

use App\Models\ProductMapping;
use App\Models\SyncJob;
use App\Models\SyncLog;
use App\Services\Ticimax\ProductService;
use Illuminate\Contracts\Console\Kernel;

$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

echo "=== Son 3 product_create job'u ===\n";
foreach (SyncJob::where('type', 'product_create')->latest('id')->take(3)->get() as $j) {
    echo "  #{$j->id} {$j->status} total={$j->total} success={$j->success_count} error={$j->error_count} başlangıç={$j->started_at}\n";
}

$lastJob = SyncJob::where('type', 'product_create')->latest('id')->first();
echo "\n=== Job #{$lastJob->id} success logları (oluşturulan/güncellenen) ===\n";
$logs = SyncLog::where('job_id', $lastJob->id)->where('status', 'success')->get(['stok_kodu', 'message']);
foreach ($logs as $l) {
    echo "  stok={$l->stok_kodu} :: {$l->message}\n";
}

echo "\n=== ProductMapping'de KLON/CLONE/TEST stok_kodlu kayıtlar ===\n";
$klon = ProductMapping::where('stok_kodu', 'like', '%KLON%')
    ->orWhere('stok_kodu', 'like', '%CLONE%')
    ->orWhere('stok_kodu', 'like', '%TEST%')
    ->get(['stok_kodu', 'ana_product_id', 'bayi_product_id', 'status', 'last_synced_at']);
echo 'Toplam: '.$klon->count()."\n";
foreach ($klon as $m) {
    echo "  stok={$m->stok_kodu} ana={$m->ana_product_id} bayi={$m->bayi_product_id} status={$m->status} synced={$m->last_synced_at}\n";
}

echo "\n=== Ana mağazada KLON/CLONE örnekleri var mı? ===\n";
$ana = ProductService::for('ana');
foreach (['KLON-20260523205957', 'CLONE-20260523210049'] as $sk) {
    $p = $ana->getProductByStokKodu($sk);
    echo "  {$sk} → ".($p && (int) ($p['ID'] ?? 0) > 0 ? 'ANA\'DA VAR (ID='.$p['ID'].')' : 'ana\'da YOK')."\n";
}
