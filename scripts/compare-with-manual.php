<?php
/**
 * Bayi'nin en son eklenen ürününü API ile çek, kullanıcının elden eklediği olmalı.
 * Bu ürünün payload'ını bizim ana→bayi payload'ı ile karşılaştır.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Ticimax\ProductMapper;
use App\Services\Ticimax\ProductService;

$bayi = ProductService::for('bayi');
$ana = ProductService::for('ana');

echo "=== Bayi'nin en son eklenen 5 ürünü (DESC) ===\n";
$latest = $bayi->getNewProducts(null, 1, 5, 'DESC');
foreach ($latest as $p) {
    $v = $p['Varyasyonlar']['Varyasyon'] ?? $p['Varyasyonlar'];
    if (isset($v['Barkod'])) { $v = [$v]; }
    $bar = $v[0]['Barkod'] ?? '-';
    echo "  ID={$p['ID']} | barkod={$bar} | {$p['UrunAdi']}\n";
}

echo "\n=== En son eklenen ürünün TAM JSON'u ===\n";
if (! empty($latest)) {
    $newest = $latest[0];
    echo json_encode($newest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}
