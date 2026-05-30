<?php

/**
 * 321 "pending" (bayide yok sanılan) ürün GERÇEKTEN bayide eksik mi,
 * yoksa job #61'in bayi taraması pagination bug yüzünden mi kaçırdı?
 *
 * Bayi stok+barkod indeksini taze kurar, 321'i kontrol eder.
 */

require __DIR__.'/../../vendor/autoload.php';

use App\Models\ProductMapping;
use App\Services\Ticimax\ProductService;
use Illuminate\Contracts\Console\Kernel;

$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

echo "=== Bayi stok+barkod indeksi (taze tam tarama) ===\n";
$bayi = ProductService::for('bayi');
$byStok = [];
$byBarkod = [];
$page = 1;
$perPage = (int) config('ticimax.batch_size', 100);
$consecBug = 0;
while (true) {
    $res = $bayi->fetchProductPageRecovering('created', null, $page, $perPage, 'ASC');
    $products = $res['products'];
    if ($res['bug']) {
        $consecBug++;
        if (empty($products) && $consecBug >= 10) {
            break;
        }
    } else {
        $consecBug = 0;
        if (empty($products)) {
            break;
        }
    }
    foreach ($products as $card) {
        $vars = $card['Varyasyonlar'] ?? [];
        if (isset($vars['Varyasyon'])) {
            $vars = is_array($vars['Varyasyon']) && array_is_list($vars['Varyasyon']) ? $vars['Varyasyon'] : [$vars['Varyasyon']];
        }
        foreach ((array) $vars as $v) {
            $s = (string) ($v['StokKodu'] ?? '');
            $b = (string) ($v['Barkod'] ?? '');
            if ($s !== '') {
                $byStok[$s] = true;
            }
            if ($b !== '') {
                $byBarkod[$b] = true;
            }
        }
    }
    if (! $res['bug'] && count($products) < $perPage) {
        break;
    }
    $page++;
}
echo 'Bayi indeks: '.count($byStok).' stok, '.count($byBarkod)." barkod\n\n";

$pending = ProductMapping::where('status', 'pending')->whereNull('bayi_product_id')->get(['stok_kodu', 'barcode', 'ana_product_id']);
echo 'Pending satır: '.$pending->count()."\n";

$reallyMissing = 0;
$falseMissing = 0;
$samplesReal = [];
foreach ($pending as $m) {
    $s = (string) ($m->stok_kodu ?? '');
    $b = (string) ($m->barcode ?? '');
    $found = ($s !== '' && isset($byStok[$s])) || ($b !== '' && isset($byBarkod[$b]));
    if ($found) {
        $falseMissing++;
    } else {
        $reallyMissing++;
        if (count($samplesReal) < 10) {
            $samplesReal[] = $m;
        }
    }
}
echo "  Aslında bayide VAR (yanlış pending) : {$falseMissing}\n";
echo "  GERÇEKTEN bayide yok               : {$reallyMissing}\n";
echo "  Gerçekten-eksik örnekler:\n";
foreach ($samplesReal as $m) {
    echo '    stok='.($m->stok_kodu ?: '-').' barkod='.($m->barcode ?: '-').' ana='.$m->ana_product_id."\n";
}
