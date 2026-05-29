<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Services\Ticimax\ProductMapper;
use App\Services\Ticimax\ProductService;
use Illuminate\Contracts\Console\Kernel;

$ana = ProductService::for('ana');
$bayi = ProductService::for('bayi');
$mapper = new ProductMapper;
$mapper->setDefaultCategoryId($bayi->getDefaultCategoryId());
$mapper->setBrandResolver(fn ($n) => $bayi->findOrCreateBrandId($n) ?: $bayi->getDefaultBrandId());
$anaSupMap = array_flip($ana->getSupplierMap());
$mapper->setSupplierResolver(function ($anaId) use ($anaSupMap, $bayi) {
    $name = $anaSupMap[$anaId] ?? '';

    return $name ? ($bayi->findOrCreateSupplierId($name) ?: $bayi->getDefaultSupplierId()) : $bayi->getDefaultSupplierId();
});

$products = $ana->getNewProducts(null, 1, 1);
$u = $products[0];
echo 'Test ürün: '.($u['UrunAdi'] ?? '?').' (ID='.($u['ID'] ?? '?').")\n";

$payload = $mapper->anaToBayiCreatePayload($u);
$stokKodu = $mapper->resolveStokKodu($u);
$primaryBarcode = $payload['Varyasyonlar'][0]['Barkod'] ?? '';

echo 'Bizim üreteceğimiz TedarikciKodu: '.$payload['TedarikciKodu']."\n";
echo "StokKodu: {$stokKodu}\n";
echo "Barkod: {$primaryBarcode}\n";

// Hibrit upsert: bayi'de var mı bak
$bayiExisting = $bayi->getProductByBarcode($primaryBarcode);
if ($bayiExisting) {
    echo "✓ Bayi'de mevcut UrunKartiID: ".($bayiExisting['ID'] ?? '?')."\n";
    $payload['ID'] = (int) $bayiExisting['ID'];

    $bayiVars = $bayiExisting['Varyasyonlar']['Varyasyon'] ?? [];
    if (is_array($bayiVars) && ! array_is_list($bayiVars)) {
        $bayiVars = [$bayiVars];
    }
    foreach ($bayiVars as $bv) {
        $bk = (string) ($bv['Barkod'] ?? '');
        foreach ($payload['Varyasyonlar'] as $i => $v) {
            if (($v['Barkod'] ?? '') === $bk) {
                $payload['Varyasyonlar'][$i]['ID'] = (int) ($bv['ID'] ?? 0);
                $payload['Varyasyonlar'][$i]['UrunKartiID'] = (int) $bayiExisting['ID'];
            }
        }
    }
    echo "Payload artık ID match ile gidiyor (UrunKartiID={$payload['ID']})\n";
}

try {
    $res = $bayi->createProduct($payload);
    echo "✅ BAŞARILI\n";
    var_export($res);
} catch (Throwable $e) {
    echo '❌ HATA: '.$e->getMessage()."\n";
}
