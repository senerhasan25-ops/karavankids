<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Ticimax\TicimaxClient;

$bayi = new TicimaxClient('bayi');

// 1) Mevcut marka listesi
echo "=== Bayi'nin GERÇEK marka listesi ===\n";
$resp = $bayi->call('product', 'SelectMarka', ['UyeKodu' => $bayi->getUyeKodu()]);
$brands = $resp->SelectMarkaResult->Marka ?? [];
if (! is_array($brands)) $brands = [$brands];
echo "Toplam: " . count($brands) . "\n";
$names = [];
foreach ($brands as $b) {
    $names[] = "ID={$b->ID}|{$b->Tanim}";
}
echo "  " . implode(", ", array_slice($names, 0, 20)) . "\n";
echo "Magu var mı? " . (in_array('Magu', array_map(fn($b) => $b->Tanim, $brands)) ? 'EVET' : 'HAYIR') . "\n";

// 2) Şimdi SaveMarka ile yeni marka deneyelim
echo "\n=== SaveMarka çağrısı: 'TestMarkaDeneme123' ===\n";
$saveResp = $bayi->call('product', 'SaveMarka', [
    'UyeKodu' => $bayi->getUyeKodu(),
    'marka' => [
        'ID' => 0,
        'Tanim' => 'TestMarkaDeneme123',
        'Aktif' => true,
        'Sira' => 0,
    ],
]);
echo "Response: " . json_encode($saveResp) . "\n";

// 3) Listeyi tekrar çek, yeni marka var mı?
echo "\n=== Tekrar marka listesi ===\n";
$resp2 = $bayi->call('product', 'SelectMarka', ['UyeKodu' => $bayi->getUyeKodu()]);
$brands2 = $resp2->SelectMarkaResult->Marka ?? [];
if (! is_array($brands2)) $brands2 = [$brands2];
echo "Yeni toplam: " . count($brands2) . " (önce " . count($brands) . ")\n";
$testMatch = array_filter($brands2, fn($b) => str_starts_with($b->Tanim, 'TestMarka'));
foreach ($testMatch as $tm) {
    echo "  BULUNDU: ID={$tm->ID} | {$tm->Tanim}\n";
}
if (empty($testMatch)) {
    echo "  Test markası bulunamadı — SaveMarka silently failing!\n";
}
