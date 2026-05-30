<?php

/**
 * Tedarikçi kodu anahtar olarak kullanılabilir mi? Gerçek veriyi ölç.
 *
 * Her mağaza için varyasyon seviyesinde:
 *   - TedarikciKodu dolu / boş sayısı
 *   - distinct TedarikciKodu (uniqueness)
 *   - TedarikciKodu2 dolu sayısı
 *   - kart seviyesinde de TedarikciKodu var mı
 *   - örnek değerler (sentetik SUP2026 mı, gerçek mi?)
 *
 * Kullanım: php scripts/debug/debug-supplier-codes.php ana
 *           php scripts/debug/debug-supplier-codes.php bayi
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
$consecBug = 0;

$kartTedDolu = 0;
$kartTedBos = 0;
$varTotal = 0;
$varTedDolu = 0;
$varTedBos = 0;
$varTed2Dolu = 0;
$distinctVarTed = [];
$distinctKartTed = [];
$samples = [];
$kartCount = 0;

echo "=== {$store} tedarikçi kodu analizi ===\n";

while ($page <= 200) {
    $res = $svc->fetchProductPageRecovering('created', null, $page, $perPage, 'ASC');
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
        $kartCount++;
        $kt = trim((string) ($card['TedarikciKodu'] ?? ''));
        if ($kt !== '') {
            $kartTedDolu++;
            $distinctKartTed[$kt] = true;
        } else {
            $kartTedBos++;
        }

        $vars = $card['Varyasyonlar'] ?? [];
        if (isset($vars['Varyasyon'])) {
            $vars = is_array($vars['Varyasyon']) && array_is_list($vars['Varyasyon']) ? $vars['Varyasyon'] : [$vars['Varyasyon']];
        }
        foreach ((array) $vars as $v) {
            $varTotal++;
            $vt = trim((string) ($v['TedarikciKodu'] ?? ''));
            $vt2 = trim((string) ($v['TedarikciKodu2'] ?? ''));
            if ($vt !== '') {
                $varTedDolu++;
                $distinctVarTed[$vt] = true;
                if (count($samples) < 12) {
                    $samples[] = "kart#{$card['ID']} stok=".($v['StokKodu'] ?? '-')." TedKodu='{$vt}' TedKodu2='{$vt2}'";
                }
            } else {
                $varTedBos++;
            }
            if ($vt2 !== '') {
                $varTed2Dolu++;
            }
        }
    }

    if (! $res['bug'] && count($products) < $perPage) {
        break;
    }
    $page++;
}

echo "Kart sayısı                  : {$kartCount}\n";
echo "  Kart TedarikciKodu DOLU    : {$kartTedDolu}\n";
echo "  Kart TedarikciKodu BOŞ     : {$kartTedBos}\n";
echo '  Distinct kart TedarikciKodu: '.count($distinctKartTed)."\n";
echo "Varyasyon sayısı             : {$varTotal}\n";
echo "  Var TedarikciKodu DOLU     : {$varTedDolu}\n";
echo "  Var TedarikciKodu BOŞ      : {$varTedBos}\n";
echo '  Distinct var TedarikciKodu : '.count($distinctVarTed)."\n";
echo "  Var TedarikciKodu2 DOLU    : {$varTed2Dolu}\n";
echo "\nÖrnek değerler:\n";
foreach ($samples as $s) {
    echo "  {$s}\n";
}
