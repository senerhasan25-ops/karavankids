<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Ticimax\ProductService;

$bayi = ProductService::for('bayi');

// Var olan bir ürünü çek (önceki testlerden barkod biliyoruz)
$barcode = '636997227832';
$urun = $bayi->getProductByBarcode($barcode);
if (! $urun) {
    echo "Bayi'de yok\n";
    exit(1);
}

echo "Test ürün: ID={$urun['ID']} | {$urun['UrunAdi']}\n";

// Bayi'den çektiğimiz objeyi olduğu gibi geri gönder, sadece UrunAdi'na ekle yap
$updatePayload = $urun;
$updatePayload['UrunAdi'] = $urun['UrunAdi'] . ' (TEST)';

try {
    $result = $bayi->updateProduct((string) $urun['ID'], $updatePayload);
    echo "✓ updateProduct döndü, sonuç ID=" . ($result['ID'] ?? '(?)') . "\n";

    // Bayi'den tekrar çekip UrunAdi değişti mi
    $verify = $bayi->getProductByBarcode($barcode);
    echo "Bayi'deki yeni isim: " . ($verify['UrunAdi'] ?? '-') . "\n";

    // Restore
    $bayi->updateProduct((string) $urun['ID'], [
        'ID' => (int) $urun['ID'],
        'UrunAdi' => str_replace(' (TEST)', '', $urun['UrunAdi']),
        'Aktif' => true,
    ]);
    echo "(İsim eski haline döndürüldü)\n";
} catch (\Throwable $e) {
    echo "✗ HATA: " . $e->getMessage() . "\n";
}
