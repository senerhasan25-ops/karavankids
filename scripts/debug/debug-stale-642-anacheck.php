<?php

/**
 * 1248 bayat satırın "642 sadece-bayat" alt grubu: ana mağazada hâlâ var mı?
 * Ana barkod indeksini tam tarama ile kurar, 642'nin barkodlarını kontrol eder.
 *
 * Sonuç yorumu:
 *  - Ana'da VAR  → yeni remap atlamış; mapping onarılmalı (bayi_product_id doldur).
 *  - Ana'da YOK  → 05-24'ten sonra anadan silinmiş; bayide öksüz (ayrı mesele).
 */

require __DIR__.'/../../vendor/autoload.php';

use App\Models\ProductMapping;
use App\Services\Ticimax\ProductService;
use Illuminate\Contracts\Console\Kernel;

$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// "642 sadece-bayat" ana ID'leri
$staleAna = ProductMapping::whereNull('bayi_product_id')->whereDate('last_synced_at', '2026-05-24')->where('status', 'synced')->pluck('ana_product_id')->filter()->unique();
$filledAna = ProductMapping::whereNotNull('bayi_product_id')->pluck('ana_product_id')->filter()->unique();
$only = $staleAna->diff($filledAna)->values();
echo 'Sadece-bayat ana ID: '.$only->count()."\n";

// Bu ana ID'lere ait bayat satırların barkodları
$rows = ProductMapping::whereNull('bayi_product_id')->whereDate('last_synced_at', '2026-05-24')->where('status', 'synced')
    ->whereIn('ana_product_id', $only->all())->get(['barcode', 'ana_product_id']);

echo "=== Ana barkod indeksi kuruluyor (tam tarama) ===\n";
$ana = ProductService::for('ana');
$anaByBarkod = [];
$page = 1;
$perPage = (int) config('ticimax.batch_size', 100);
$consecBug = 0;
while (true) {
    $res = $ana->fetchProductPageRecovering('created', null, $page, $perPage, 'ASC');
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
            $b = (string) ($v['Barkod'] ?? '');
            if ($b !== '') {
                $anaByBarkod[$b] = (int) ($card['ID'] ?? 0);
            }
        }
    }
    if (! $res['bug'] && count($products) < $perPage) {
        break;
    }
    $page++;
}
echo 'Ana indeks: '.count($anaByBarkod)." barkod\n\n";

$inAna = 0;
$notInAna = 0;
$samplesNot = [];
foreach ($rows as $m) {
    $b = (string) ($m->barcode ?? '');
    if ($b !== '' && isset($anaByBarkod[$b])) {
        $inAna++;
    } else {
        $notInAna++;
        if (count($samplesNot) < 8) {
            $samplesNot[] = $m;
        }
    }
}
echo "=== 642 sadece-bayat — ana mağaza kontrolü ===\n";
echo "  Ana'da VAR (remap atlamış, onarılmalı) : {$inAna}\n";
echo "  Ana'da YOK (öksüz, anadan silinmiş)    : {$notInAna}\n";
echo "  Örnek ana'da-YOK:\n";
foreach ($samplesNot as $m) {
    echo "    barkod={$m->barcode} ana_id={$m->ana_product_id}\n";
}
