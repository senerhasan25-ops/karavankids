<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Services\Ticimax\ProductMapper;
use App\Services\Ticimax\ProductService;
use Illuminate\Contracts\Console\Kernel;

$barcode = $argv[1] ?? '636997239705';
echo "=== Testing barcode: {$barcode} ===\n";

$ana = ProductService::for('ana');
$bayi = ProductService::for('bayi');
$mapper = new ProductMapper;
$mapper->setBrandResolver(fn (string $name) => $bayi->findOrCreateBrandId($name));
$anaSupplierIdToName = array_flip($ana->getSupplierMap());
$mapper->setSupplierResolver(function (int $anaId) use ($anaSupplierIdToName, $bayi) {
    $name = $anaSupplierIdToName[$anaId] ?? '';

    return $name ? $bayi->findOrCreateSupplierId($name) : 0;
});

echo "1) Ana mağazadan ürün çekiliyor...\n";
$anaUrun = $ana->getProductByBarcode($barcode);
if (! $anaUrun) {
    echo "ANA'da bulunamadı.\n";
    exit(1);
}
echo "   ID={$anaUrun['ID']} | {$anaUrun['UrunAdi']}\n";
$v = $anaUrun['Varyasyonlar']['Varyasyon'] ?? $anaUrun['Varyasyonlar'] ?? [];
if (isset($v['Barkod'])) {
    $v = [$v];
}
foreach ($v as $variant) {
    echo "   Varyasyon: barkod={$variant['Barkod']} fiyat={$variant['SatisFiyati']} ParaBirimi={$variant['ParaBirimi']} ParaBirimiID={$variant['ParaBirimiID']} kdv={$variant['KdvOrani']}\n";
}

echo "\n2) Bayi'de bu barkod var mı?\n";
$bayiUrun = $bayi->getProductByBarcode($barcode);
if ($bayiUrun) {
    echo "   ZATEN VAR: ID={$bayiUrun['ID']} | {$bayiUrun['UrunAdi']}\n";
    echo "   → mapping kaydı yazılır, create yapılmaz.\n";
    exit(0);
}
echo "   Yok. Şimdi create deneyeceğiz.\n";

echo "\n3) Mapper payload üretiliyor...\n";
$payload = $mapper->anaToBayiCreatePayload($anaUrun);
echo "   UrunKarti.UrunAdi={$payload['UrunAdi']}\n";
echo '   UrunKarti.Aktif='.($payload['Aktif'] ? 'true' : 'false')."\n";
echo '   Varyasyon sayısı='.count($payload['Varyasyonlar'])."\n";
foreach ($payload['Varyasyonlar'] as $i => $vr) {
    echo "   [{$i}] barkod={$vr['Barkod']} fiyat={$vr['SatisFiyati']} ParaBirimiID={$vr['ParaBirimiID']} kdv={$vr['KdvOrani']}\n";
}

echo "\n4) Bayi'ye SaveUrun gönderiliyor...\n";
try {
    $created = $bayi->createProduct($payload);
    echo '✓ BAŞARILI! Yeni ID='.($created['ID'] ?? '(?)')."\n";
    echo "Full response:\n";
    echo json_encode($created, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
} catch (Throwable $e) {
    echo '✗ HATA: '.$e->getMessage()."\n";
    exit(1);
}
