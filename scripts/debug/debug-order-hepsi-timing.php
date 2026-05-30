<?php

/**
 * D doğrulaması: Hepsi modu artık tek çağrı mı, kaç sipariş, ne kadar sürüyor?
 * Kullanım: php scripts/debug/debug-order-hepsi-timing.php bayi
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';

use App\Services\Ticimax\OrderService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

Cache::flush();
$store = $argv[1] ?? 'bayi';
$svc = OrderService::for($store);

$t = microtime(true);
$orders = $svc->getOrdersByFilter([
    'date_from' => now()->subDays(90)->format('Y-m-d'),
    'date_to' => now()->format('Y-m-d'),
    'siparis_durumu' => -1, // Hepsi
], 1, 25);
$dt = round((microtime(true) - $t) * 1000);

echo "Hepsi modu (sayfa 1, perPage 25): ".count($orders)." sipariş, {$dt}ms\n";
$ids = array_map(fn ($o) => (string) ($o['ID'] ?? $o['SiparisID'] ?? '?'), $orders);
echo "İlk sayfa ID'ler (DESC bekleniyor): ".implode(',', $ids)."\n";
echo $dt < 800 ? "✓ Tek çağrı hızında — D çalışıyor.\n" : "✗ Hâlâ yavaş.\n";
