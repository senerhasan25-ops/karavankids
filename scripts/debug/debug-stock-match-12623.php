<?php

/**
 * Stok/fiyat delta neden testurunnnnnDSD (12623) güncellemiyor?
 * Delta'dan dönen ürünün HAM varyasyon yapısını dök + mapping eşleşmesini test et.
 */

require __DIR__.'/../../vendor/autoload.php';

use App\Models\ProductMapping;
use App\Services\Ticimax\ProductService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;

$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$ana = ProductService::for('ana');
$since = Carbon::now()->subDays(2);

echo "=== stock_price delta — testurunnnnnDSD / 12623 ham yapı ===\n";
$page = 1;
$consecBug = 0;
$dumped = false;
while ($page <= 50 && ! $dumped) {
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
        if ((int) ($prod['ID'] ?? 0) === 12623) {
            echo "BULUNDU kart ID=12623\n";
            echo "  Kart TedarikciKodu='".($prod['TedarikciKodu'] ?? '(yok)')."'\n";
            $vars = $prod['Varyasyonlar'] ?? [];
            if (isset($vars['Varyasyon'])) {
                $vars = is_array($vars['Varyasyon']) && array_is_list($vars['Varyasyon']) ? $vars['Varyasyon'] : [$vars['Varyasyon']];
            }
            echo '  Varyasyon sayısı: '.count((array) $vars)."\n";
            foreach ((array) $vars as $i => $v) {
                echo "  --- var[{$i}] ---\n";
                echo '    StokKodu='.var_export($v['StokKodu'] ?? null, true)."\n";
                echo '    TedarikciKodu='.var_export($v['TedarikciKodu'] ?? null, true)."\n";
                echo '    StokAdedi='.var_export($v['StokAdedi'] ?? null, true)."\n";
                echo '    SatisFiyati='.var_export($v['SatisFiyati'] ?? null, true)."\n";
                echo '    anahtarlar: '.implode(', ', array_keys($v))."\n";
            }
            $dumped = true;
            break;
        }
    }
    if (! $res['bug'] && count($products) < 100) {
        break;
    }
    $page++;
}
if (! $dumped) {
    echo "12623 stock_price delta'da BULUNAMADI (since={$since})\n";
}

echo "\n=== Mapping eşleşme testi ===\n";
$byTed = ProductMapping::whereNotNull('bayi_variant_id')->whereNotNull('tedarikci_kodu')->get()->keyBy('tedarikci_kodu');
$byStok = ProductMapping::whereNotNull('bayi_variant_id')->whereNotNull('stok_kodu')->get()->keyBy('stok_kodu');
echo 'byTed sayısı: '.$byTed->count().' | byStok sayısı: '.$byStok->count()."\n";
$mTed = $byTed->get('SUP2026|testurunnnnnDSD|12636');
$mStok = $byStok->get('testurunnnnnDSD');
echo 'byTed->get(SUP2026|testurunnnnnDSD|12636): '.($mTed ? "VAR (bayiVar={$mTed->bayi_variant_id}, lastStok={$mTed->last_stock})" : 'YOK')."\n";
echo 'byStok->get(testurunnnnnDSD): '.($mStok ? "VAR (bayiVar={$mStok->bayi_variant_id}, lastStok={$mStok->last_stock})" : 'YOK')."\n";
