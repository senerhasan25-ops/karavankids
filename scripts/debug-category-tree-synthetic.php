<?php
/**
 * Derin agaç senaryosu: sentetik ana_tree ile mirrorCategoryFromAna'yi izole test et.
 * Bayide gercekten root→child agaci kuruluyor mu? E2E SaveUrun yok, sadece kategori akisi.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Ticimax\ProductService;

$bayi = ProductService::for('bayi');

// Sentetik ana agaç (PID ile gerçek hiyerarsi)
$stamp = date('His');
$anaTree = [
    7001 => ['ID' => 7001, 'PID' => 0,    'Tanim' => "TestRoot-$stamp"],
    7002 => ['ID' => 7002, 'PID' => 7001, 'Tanim' => "TestParti-$stamp"],
    7003 => ['ID' => 7003, 'PID' => 7002, 'Tanim' => "TestPecete-$stamp"],
];

echo "=== Sentetik ana agaci ===\n";
foreach ($anaTree as $n) {
    echo "  ID=" . $n['ID'] . " PID=" . $n['PID'] . " | " . $n['Tanim'] . "\n";
}

echo "\n=== mirrorCategoryFromAna(leaf=7003) ===\n";
$bayiLeaf = $bayi->mirrorCategoryFromAna(7003, $anaTree);
echo "Donen bayi leaf ID: $bayiLeaf\n";

// Bayi cache'i yenilesin (yeni eklenen kategoriler gozuksun)
$prop = new ReflectionProperty($bayi, 'categoryTreeCache');
$prop->setAccessible(true);
$prop->setValue($bayi, null);

$bTree = $bayi->getCategoryTree();
echo "\n=== Bayi'de olusan yol ===\n";
$cur = $bTree[$bayiLeaf] ?? null;
$path = [];
while ($cur) {
    array_unshift($path, $cur);
    $pid = (int) $cur['PID'];
    $cur = $pid > 0 ? ($bTree[$pid] ?? null) : null;
}
foreach ($path as $i => $n) {
    echo str_repeat('  ', $i) . "└─ ID=" . $n['ID'] . " PID=" . $n['PID'] . " | " . $n['Tanim'] . "\n";
}

if (count($path) === 3) {
    echo "\n✓ AGACTAKI 3 NODE BAYIDE OLUSTURULDU\n";
} else {
    echo "\n✗ Beklenen 3 node, bulunan " . count($path) . "\n";
}
