<?php

/**
 * getOrderById artık tek SOAP çağrısı mı yapıyor? (A düzeltmesi doğrulaması)
 * Kullanım: php scripts/debug/debug-order-byid-timing.php bayi
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';

use App\Services\Ticimax\OrderService;
use Illuminate\Contracts\Console\Kernel;

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$store = $argv[1] ?? 'bayi';
$svc = OrderService::for($store);

// referans id
$list = $svc->getOrdersByFilter([
    'date_from' => now()->subDays(30)->format('Y-m-d'),
    'date_to' => now()->format('Y-m-d'),
    'siparis_durumu' => -1,
], 1, 1);
$id = (int) ($list[0]['ID'] ?? $list[0]['SiparisID'] ?? 0);
echo "Referans SiparisID = {$id}\n";

$t = microtime(true);
$o = $svc->getOrderById($id);
$dt = round((microtime(true) - $t) * 1000);
echo 'getOrderById('.$id.') → '.($o ? 'BULUNDU ✓' : 'BULUNAMADI ✗')." ({$dt}ms)\n";
echo $dt < 800 ? "Tek çağrı hızında — A çalışıyor.\n" : "Hâlâ yavaş — Hepsi modunda olabilir.\n";
