<?php
/**
 * Bayi'de olmayan ürünleri 100'lük sayfalar halinde bulur, ilk N tanesi için SaveUrun dener.
 * Rate-limit (42s) ve SaveUrunResult=0 sessiz başarısızlık otomatik handle edilir.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Ticimax\ProductMapper;
use App\Services\Ticimax\ProductService;

$maxTry = (int) ($argv[1] ?? 5); // Kaç farklı ürün denesin

$ana = ProductService::for('ana');
$bayi = ProductService::for('bayi');
$mapper = new ProductMapper();

$defaultBrandId = $bayi->getDefaultBrandId();
$defaultSupplierId = $bayi->getDefaultSupplierId();
$mapper->setBrandResolver(function (string $name) use ($bayi, $defaultBrandId) {
    $id = $bayi->findOrCreateBrandId($name);
    return $id > 0 ? $id : $defaultBrandId;
});
$anaSupplierIdToName = array_flip($ana->getSupplierMap());
$mapper->setSupplierResolver(function (int $anaId) use ($anaSupplierIdToName, $bayi, $defaultSupplierId) {
    $name = $anaSupplierIdToName[$anaId] ?? '';
    $id = $name ? $bayi->findOrCreateSupplierId($name) : 0;
    return $id > 0 ? $id : $defaultSupplierId;
});

echo "Default bayi marka ID: {$defaultBrandId} | tedarikçi ID: {$defaultSupplierId}\n";
echo "Bayi'de olmayan ana ürünleri aranıyor (100'lük sayfa, DESC)...\n\n";

$missing = [];
$seenBarcodes = []; // dedupe
$page = 1;

while (count($missing) < $maxTry && $page <= 100) {
    $products = $ana->getNewProducts(null, $page, 100, 'DESC');
    if (empty($products)) {
        break;
    }
    foreach ($products as $urun) {
        $v = $urun['Varyasyonlar']['Varyasyon'] ?? $urun['Varyasyonlar'] ?? [];
        if (isset($v['Barkod'])) { $v = [$v]; }
        if (! is_array($v) || empty($v)) { continue; }
        $barcode = $v[0]['Barkod'] ?? null;
        if (! $barcode || isset($seenBarcodes[$barcode])) { continue; }
        // Mg prefix'li test data atla (ana mağazada tekrarlı/test verisi)
        if (str_starts_with($barcode, 'Mg')) { continue; }
        $seenBarcodes[$barcode] = true;

        $bayiCheck = $bayi->getProductByBarcode($barcode);
        if (! $bayiCheck) {
            $missing[] = $urun;
            echo "  [" . count($missing) . "] {$barcode} | ID={$urun['ID']} | {$urun['UrunAdi']}\n";
            if (count($missing) >= $maxTry) {
                break 2;
            }
        }
    }
    $page++;
}

if (empty($missing)) {
    echo "Bayi'de olmayan ürün bulunamadı.\n";
    exit(0);
}

echo "\n=== Toplam " . count($missing) . " ürün denenecek ===\n";

$ok = 0;
$fail = 0;
foreach ($missing as $i => $urun) {
    $v = $urun['Varyasyonlar']['Varyasyon'] ?? $urun['Varyasyonlar'];
    if (isset($v['Barkod'])) { $v = [$v]; }
    $bar = $v[0]['Barkod'];
    echo "\n[" . ($i + 1) . "] Deneme: {$bar} | {$urun['UrunAdi']}\n";

    try {
        $payload = $mapper->anaToBayiCreatePayload($urun);
        $created = $bayi->createProduct($payload);
        $newId = (int) ($created['ID'] ?? 0);
        if ($newId === 0) {
            // Barkodla yeniden çek
            $verify = $bayi->getProductByBarcode($bar);
            $newId = (int) ($verify['ID'] ?? 0);
        }
        if ($newId > 0) {
            echo "  ✓ BAŞARILI! Bayi ID={$newId}\n";
            $ok++;
        } else {
            echo "  ⚠ SaveUrun OK görünüyor ama bayi'de bulamadık (ID=0)\n";
            $fail++;
        }
    } catch (\Throwable $e) {
        echo "  ✗ " . substr($e->getMessage(), 0, 250) . "\n";
        $fail++;
    }
}

echo "\n=== Sonuç: başarılı={$ok}, başarısız={$fail} ===\n";
