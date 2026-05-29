<?php

/**
 * Ana magazadaki gercek urun sayisini ve neden listede 65 gozuktugunu tespit et.
 */

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Services\Ticimax\ProductService;
use Illuminate\Contracts\Console\Kernel;

$ana = ProductService::for('ana');

// Sayfa 1: 100 talep et
echo "=== getNewProducts(null, 1, 100, 'DESC') ===\n";
$batch1 = $ana->getNewProducts(null, 1, 100, 'DESC');
echo 'Donen toplam UrunKarti sayisi: '.count($batch1)."\n";

// Kac tanesinde varyasyon var?
$withVar = 0;
$withoutVar = 0;
$totalVariants = 0;
foreach ($batch1 as $p) {
    $v = $p['Varyasyonlar']['Varyasyon'] ?? $p['Varyasyonlar'] ?? null;
    if ($v) {
        if (isset($v['Barkod'])) {
            $v = [$v];
        }
        if (is_array($v) && ! empty($v)) {
            $withVar++;
            $totalVariants += count($v);
        } else {
            $withoutVar++;
        }
    } else {
        $withoutVar++;
    }
}
echo "Varyasyonlu urun: $withVar | Varyasyonsuz: $withoutVar\n";
echo "Toplam varyasyon (= tabloda satir): $totalVariants\n";

// Sayfa 2 dene
echo "\n=== getNewProducts(null, 2, 100, 'DESC') ===\n";
$batch2 = $ana->getNewProducts(null, 2, 100, 'DESC');
echo 'Donen toplam: '.count($batch2)."\n";

// Sayfa 1 farkli perPage'lerle
echo "\n=== perPage testleri (sayfa 1) ===\n";
foreach ([50, 100, 200, 500, 1000] as $pp) {
    try {
        $b = $ana->getNewProducts(null, 1, $pp, 'DESC');
        echo "perPage=$pp → ".count($b)." urun donduruldu\n";
    } catch (Throwable $e) {
        echo "perPage=$pp → HATA: ".substr($e->getMessage(), 0, 100)."\n";
    }
}

// Toplam urun sayisi tahmin et — sayfalama ile yuru
echo "\n=== Toplam urun sayma (100'luk sayfalama) ===\n";
$page = 1;
$total = 0;
while ($page <= 50) {
    $b = $ana->getNewProducts(null, $page, 100, 'DESC');
    $count = count($b);
    $total += $count;
    echo "  Sayfa $page: $count urun (toplam: $total)\n";
    if ($count === 0) {
        break;
    }
    if ($count < 100) {
        echo "  → Bu son sayfa (tam 100 donmedi)\n";
        break;
    }
    $page++;
}
echo "\nAna magazadaki TOPLAM urun: $total\n";
