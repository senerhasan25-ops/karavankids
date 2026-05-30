<?php

/**
 * Bayat (05-24 tarihli, stok_kodu boş, barkodlu, status=synced ama bayi_product_id NULL)
 * 1248 mapping satırının barkodları bayide GERÇEKTEN var mı?
 *
 * Bayi barkod indeksini tam tarama ile bir kez kurar, sonra üyelik kontrolü yapar.
 * 1248 ayrı SOAP çağrısı YOK.
 *
 * Kullanım: php scripts/debug/debug-stale-barcode-check.php
 */

require __DIR__.'/../../vendor/autoload.php';

use App\Models\ProductMapping;
use App\Services\Ticimax\ProductService;
use Illuminate\Contracts\Console\Kernel;

$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

echo "=== Bayi barkod indeksi kuruluyor (tam tarama) ===\n";
$bayi = ProductService::for('bayi');
$byBarkod = [];
$byStok = [];
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
        $cardId = (int) ($card['ID'] ?? 0);
        $vars = $card['Varyasyonlar'] ?? [];
        if (isset($vars['Varyasyon'])) {
            $vars = is_array($vars['Varyasyon']) && array_is_list($vars['Varyasyon']) ? $vars['Varyasyon'] : [$vars['Varyasyon']];
        }
        foreach ((array) $vars as $v) {
            $b = (string) ($v['Barkod'] ?? '');
            $s = (string) ($v['StokKodu'] ?? '');
            if ($b !== '') {
                $byBarkod[$b] = $cardId;
            }
            if ($s !== '') {
                $byStok[$s] = $cardId;
            }
        }
    }

    if (! $res['bug'] && count($products) < $perPage) {
        break;
    }
    $page++;
}

echo 'Bayi indeks: '.count($byBarkod).' barkod, '.count($byStok)." stok_kodu\n\n";

echo "=== Bayat 1248 satır kontrolü ===\n";
$stale = ProductMapping::whereNull('bayi_product_id')
    ->whereDate('last_synced_at', '2026-05-24')
    ->where('status', 'synced')
    ->get(['id', 'barcode', 'stok_kodu', 'ana_product_id']);

echo 'Bayat satır: '.$stale->count()."\n";

$inBayi = [];      // barkod bayide VAR → aslında senkron, bayi_product_id doldurulmalı
$notInBayi = [];   // barkod bayide YOK → gerçekten eksik (pending olmalı)
$noBarcode = [];

foreach ($stale as $m) {
    $b = (string) ($m->barcode ?? '');
    if ($b === '') {
        $noBarcode[] = $m;

        continue;
    }
    if (isset($byBarkod[$b])) {
        $inBayi[] = ['m' => $m, 'bayi_card' => $byBarkod[$b]];
    } else {
        $notInBayi[] = $m;
    }
}

echo '  Bayide VAR (barkod eşleşti)     : '.count($inBayi)."\n";
echo '  Bayide YOK (gerçekten eksik)    : '.count($notInBayi)."\n";
echo '  Barkodu boş satır               : '.count($noBarcode)."\n\n";

echo "=== Bayide VAR örnekleri (ilk 8) — bunlar yanlışlıkla NULL kalmış ===\n";
foreach (array_slice($inBayi, 0, 8) as $row) {
    echo "  barkod={$row['m']->barcode} ana={$row['m']->ana_product_id} → bayi kart #{$row['bayi_card']}\n";
}

echo "\n=== Bayide YOK örnekleri (ilk 8) — gerçekten eksik ===\n";
foreach (array_slice($notInBayi, 0, 8) as $m) {
    echo "  barkod={$m->barcode} ana={$m->ana_product_id}\n";
}
