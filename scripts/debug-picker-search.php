<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Services\Ticimax\ProductService;

$ana = ProductService::for('ana');

// Ana'da gercek bir stok kodu bul
$sample = $ana->getNewProducts(null, 1, 5, 'DESC');
$realSk = null;
foreach ($sample as $p) {
    $v = $p['Varyasyonlar']['Varyasyon'] ?? $p['Varyasyonlar'];
    if (isset($v['Barkod'])) $v = [$v];
    if (! empty($v[0]['StokKodu'])) { $realSk = $v[0]['StokKodu']; break; }
}
echo "Gercek StokKodu ornegi (DESC ilk): $realSk\n\n";

echo "=== Tekli birebir: '$realSk' ===\n";
$res = $ana->searchProductsByStokKodu($realSk);
echo "  bulundu: " . count($res) . "\n";
foreach ($res as $u) echo "  - ID=" . $u['ID'] . " | " . ($u['UrunAdi'] ?? '?') . "\n";

// Coklu icin DESC'ten 3 farkli SK al
$skList = [];
foreach ($sample as $p) {
    $v = $p['Varyasyonlar']['Varyasyon'] ?? $p['Varyasyonlar'];
    if (isset($v['Barkod'])) $v = [$v];
    if (! empty($v[0]['StokKodu'])) $skList[] = $v[0]['StokKodu'];
    if (count($skList) >= 3) break;
}
$multi = implode(', ', $skList);
echo "\n=== Coklu: '$multi' ===\n";
$res = $ana->searchProductsByStokKodu($multi);
echo "  bulundu: " . count($res) . "\n";
foreach ($res as $u) echo "  - ID=" . $u['ID'] . " | " . ($u['UrunAdi'] ?? '?') . "\n";

// LIKE testi: gercek SK'nin ilk 3-4 karakterini al
$prefix = mb_substr((string) $realSk, 0, 3);
echo "\n=== Tekli LIKE (birebir yok, scan): '$prefix' (kismi) ===\n";
$res = $ana->searchProductsByStokKodu($prefix);
echo "  bulundu: " . count($res) . "\n";
foreach (array_slice($res, 0, 5) as $u) {
    $v = $u['Varyasyonlar']['Varyasyon'] ?? $u['Varyasyonlar'];
    if (isset($v['Barkod'])) $v = [$v];
    echo "  - SK=" . ($v[0]['StokKodu'] ?? '?') . " | " . substr($u['UrunAdi'] ?? '?', 0, 60) . "\n";
}
