<?php

/**
 * Bir mağazadaki ürünleri sayfa sayfa gez, GERÇEK durumu raporla:
 *   - kaç sayfa çekildi, ham satır (kart) sayısı
 *   - DISTINCT kart ID sayısı (örtüşme = ham - distinct)
 *   - min/max ID, bug sayfaları, tekrar eden sayfalar
 *
 * Kullanım: php scripts/debug/debug-enumerate-products.php ana
 *           php scripts/debug/debug-enumerate-products.php bayi
 */

require __DIR__.'/../../vendor/autoload.php';

use App\Services\Ticimax\ProductService;
use Illuminate\Contracts\Console\Kernel;

$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$store = $argv[1] ?? 'ana';
$svc = ProductService::for($store);

$perPage = 100;
$page = 1;
$rawCards = 0;
$distinct = [];
$bugPages = 0;
$emptyHits = 0;
$consecutiveBug = 0;
$prevPageFirstId = null;
$overlapPages = 0;
$minId = PHP_INT_MAX;
$maxId = 0;

echo "=== {$store} ürün sayımı (perPage={$perPage}, created/null=tüm) ===\n";

while ($page <= 200) {
    $res = $svc->fetchProductPageRecovering('created', null, $page, $perPage, 'ASC');
    $products = $res['products'];
    $n = count($products);

    if ($res['bug']) {
        $bugPages++;
        $consecutiveBug++;
    } else {
        $consecutiveBug = 0;
    }

    if ($n === 0) {
        if (! $res['bug']) {
            echo "  Sayfa {$page}: 0 (gerçek son)\n";
            break;
        }
        $emptyHits++;
        if ($consecutiveBug >= 10) {
            echo "  Sayfa {$page}: 0 (10 ardışık bug → son varsay)\n";
            break;
        }
        $page++;

        continue;
    }

    $ids = array_map(fn ($p) => (int) ($p['ID'] ?? 0), $products);
    $firstId = $ids[0] ?? null;
    $newInPage = 0;
    foreach ($ids as $id) {
        if ($id <= 0) {
            continue;
        }
        $minId = min($minId, $id);
        $maxId = max($maxId, $id);
        if (! isset($distinct[$id])) {
            $distinct[$id] = true;
            $newInPage++;
        }
    }
    $rawCards += $n;
    if ($newInPage === 0) {
        $overlapPages++;
    }

    $flag = $res['bug'] ? ' [BUG-recover]' : '';
    $dup = $n - $newInPage;
    echo "  Sayfa {$page}: ham={$n} yeni={$newInPage} tekrar={$dup} ilkID={$firstId}{$flag}\n";

    if (! $res['bug'] && $n < $perPage) {
        echo "  → son sayfa (ham<{$perPage})\n";
        break;
    }
    $page++;
}

echo "\nSONUÇ ({$store}):\n";
echo '  Ham kart toplam (tekrarlı): '.$rawCards."\n";
echo '  DISTINCT kart ID          : '.count($distinct)."\n";
echo '  Örtüşme (ham - distinct)  : '.($rawCards - count($distinct))."\n";
echo '  Tamamen tekrar sayfa      : '.$overlapPages."\n";
echo '  Bug sayfa                 : '.$bugPages."\n";
echo '  min ID / max ID           : '.($minId === PHP_INT_MAX ? '-' : $minId).' / '.$maxId."\n";
