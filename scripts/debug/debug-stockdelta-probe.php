<?php

/**
 * Stok/fiyat delta neden 12623'ü (testurunnnnnDSD) kaçırdı?
 * - Ana'da 12623'ün güncel stok + StokGuncellemeTarihi
 * - Çekirdek soru: stock_price delta filtresi 12623'ü döndürüyor mu?
 */

require __DIR__.'/../../vendor/autoload.php';

use App\Services\Ticimax\ProductService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;

$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$ana = ProductService::for('ana');

echo "=== Ana: testurunnnnnDSD tek ürün ===\n";
$p = $ana->getProductByStokKodu('testurunnnnnDSD');
if ($p) {
    echo '  KartID='.($p['ID'] ?? '?').' StokGuncellemeTarihi='.($p['StokGuncellemeTarihi'] ?? '(yok)').' EklemeTarihi='.($p['EklemeTarihi'] ?? '(yok)')."\n";
    $vars = $p['Varyasyonlar']['Varyasyon'] ?? $p['Varyasyonlar'] ?? [];
    if (isset($vars['ID'])) {
        $vars = [$vars];
    }
    foreach ((array) $vars as $v) {
        echo '    var StokKodu='.($v['StokKodu'] ?? '?').' StokAdedi='.($v['StokAdedi'] ?? '?').' SatisFiyati='.($v['SatisFiyati'] ?? '?')."\n";
    }
} else {
    echo "  BULUNAMADI\n";
}

foreach (['2026-05-30 05:00:00', '2026-05-30 05:52:39', '2026-05-30 06:30:00'] as $sinceStr) {
    $since = Carbon::parse($sinceStr);
    echo "\n=== stock_price delta — since {$sinceStr} ===\n";
    $page = 1;
    $found = [];
    $consecBug = 0;
    while ($page <= 50) {
        $res = $ana->fetchProductPageRecovering('stock_price', $since, $page, 100, 'ASC');
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
        foreach ($products as $prod) {
            $vars = $prod['Varyasyonlar']['Varyasyon'] ?? $prod['Varyasyonlar'] ?? [];
            if (isset($vars['ID'])) {
                $vars = [$vars];
            }
            $sk = '';
            foreach ((array) $vars as $v) {
                $sk = (string) ($v['StokKodu'] ?? '');
                if ($sk !== '') {
                    break;
                }
            }
            $found[] = ($prod['ID'] ?? '?').':'.$sk.' (SGT='.($prod['StokGuncellemeTarihi'] ?? '-').')';
        }
        if (! $res['bug'] && count($products) < 100) {
            break;
        }
        $page++;
    }
    echo '  dönen ürün sayısı: '.count($found)."\n";
    foreach (array_slice($found, 0, 30) as $f) {
        echo '    '.$f."\n";
    }
}
