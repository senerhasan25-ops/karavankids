<?php

/**
 * Bayi'de elden eklenen son ürünü çek, klonla (ID=0, yeni barkod), SaveUrun ile gönder.
 * Eğer başarılı olursa = bizim payload'da eksik alanlar var demek.
 */

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Services\Ticimax\ProductService;
use Illuminate\Contracts\Console\Kernel;

$bayi = ProductService::for('bayi');

echo "=== Bayi'nin en son eklenen ürününü çekiyoruz ===\n";
$latest = $bayi->getNewProducts(null, 1, 1, 'DESC');
if (empty($latest)) {
    echo "Boş\n";
    exit(1);
}
$template = $latest[0];
echo "Şablon: ID={$template['ID']} | {$template['UrunAdi']}\n";

// Klonla
$clone = $template;
$clone['ID'] = 0;
$clone['UrunAdi'] = 'KLON TEST — '.date('Y-m-d H:i');

$newBarcode = 'KLON-'.date('YmdHis');

// Varyasyonları temizle, yeni ID + yeni barkod
$varList = $clone['Varyasyonlar']['Varyasyon'] ?? $clone['Varyasyonlar'];
if (isset($varList['Barkod'])) {
    $varList = [$varList];
}
$cleanedVariants = [];
foreach ($varList as $v) {
    $v['ID'] = 0;
    $v['UrunKartiID'] = 0;
    $v['Barkod'] = $newBarcode;
    $v['StokKodu'] = $newBarcode;
    $v['StokAdedi'] = 7;
    $v['SatisFiyati'] = 99.90;
    $cleanedVariants[] = $v;
}
$clone['Varyasyonlar'] = $cleanedVariants;
$clone['UrunSayfaAdresi'] = '';
unset($clone['EklemeTarihi'], $clone['DuzenleyenKullanici'], $clone['EkleyenKullanici']);

echo "\n=== Klon oluşturuluyor: barkod={$newBarcode} ===\n";
try {
    $created = $bayi->createProduct($clone);
    $newId = (int) ($created['ID'] ?? 0);
    if ($newId === 0) {
        $verify = $bayi->getProductByBarcode($newBarcode);
        $newId = (int) ($verify['ID'] ?? 0);
    }
    if ($newId > 0) {
        echo "✓ BAŞARILI! Yeni ID={$newId}\n";
        echo "  Bizim eldeki payload yeterli + bu şablon uygun.\n";
    } else {
        echo "✗ Yine SaveUrunResult=0\n";
    }
} catch (Throwable $e) {
    echo '✗ '.substr($e->getMessage(), 0, 250)."\n";
}
