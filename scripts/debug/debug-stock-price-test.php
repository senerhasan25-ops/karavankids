<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\ProductMapping;
use App\Services\Ticimax\ProductService;
use Illuminate\Contracts\Console\Kernel;

// İlk 5 bilinen barkod (üst-üst görmüştük)
$barcodes = [
    '636997227832' => 'Meri Meri - Deniz Kızı Poster',
    '636997229454' => 'Meri Meri - Kamp Poster',
    '011964506255' => 'Manhattan Toy Baby Stella',
    '636997232089' => 'Meri Meri - YAY Poster',
    '636997240763' => 'Meri Meri - Cat & Dog',
];

$ana = ProductService::for('ana');
$bayi = ProductService::for('bayi');

echo "=== Adım 1: 5 ürünün ana/bayi UrunKartiID'lerini yakala ===\n";
foreach ($barcodes as $bar => $name) {
    $a = $ana->getProductByBarcode($bar);
    $b = $bayi->getProductByBarcode($bar);
    if (! $a || ! $b) {
        echo "  ✗ {$bar} | ana=".($a ? 'OK' : 'NO').' bayi='.($b ? 'OK' : 'NO')."\n";

        continue;
    }
    $anaV = is_array($a['Varyasyonlar']) ? ($a['Varyasyonlar']['Varyasyon'] ?? $a['Varyasyonlar']) : [];
    if (isset($anaV['Barkod'])) {
        $anaV = [$anaV];
    }
    $bayiV = is_array($b['Varyasyonlar']) ? ($b['Varyasyonlar']['Varyasyon'] ?? $b['Varyasyonlar']) : [];
    if (isset($bayiV['Barkod'])) {
        $bayiV = [$bayiV];
    }

    $anaVar = $anaV[0] ?? null;
    $bayiVar = $bayiV[0] ?? null;

    ProductMapping::updateOrCreate(
        ['barcode' => $bar],
        [
            'ana_product_id' => (string) $a['ID'],
            'bayi_product_id' => (string) $b['ID'],
            'last_price' => (float) ($anaVar['SatisFiyati'] ?? 0),
            'last_stock' => (int) ($anaVar['StokAdedi'] ?? 0),
            'status' => 'synced',
            'last_synced_at' => now(),
        ]
    );
    echo "  ✓ {$bar} | ana_UK={$a['ID']} bayi_UK={$b['ID']} | ana_stok={$anaVar['StokAdedi']} bayi_stok={$bayiVar['StokAdedi']} | ana_fiyat={$anaVar['SatisFiyati']} bayi_fiyat={$bayiVar['SatisFiyati']}\n";
    // Ayrıca varyasyon ID'lerini de göster (stok/fiyat update'i bunu kullanacak)
    echo "    ana_varID={$anaVar['ID']} bayi_varID={$bayiVar['ID']}\n";
}

echo "\n=== Adım 2: Stok update test (bir varyasyon için) ===\n";
$testMapping = ProductMapping::first();
if ($testMapping) {
    // Bayi tarafının varyasyon ID'sini al
    $bayiProd = $bayi->getProductByBarcode($testMapping->barcode);
    $bayiV = is_array($bayiProd['Varyasyonlar']) ? ($bayiProd['Varyasyonlar']['Varyasyon'] ?? $bayiProd['Varyasyonlar']) : [];
    if (isset($bayiV['Barkod'])) {
        $bayiV = [$bayiV];
    }
    $bayiVarId = $bayiV[0]['ID'] ?? 0;
    $oldStock = (int) ($bayiV[0]['StokAdedi'] ?? 0);
    $newStock = $oldStock + 1; // 1 arttır

    echo "Test ürün: {$testMapping->barcode} | bayi UK={$testMapping->bayi_product_id} bayi Varyasyon ID={$bayiVarId}\n";
    echo "  Eski bayi stok: {$oldStock}\n";
    echo "  Yeni stok yazılıyor: {$newStock}\n";

    try {
        echo "  → StokAdediGuncelle (varyasyon ID={$bayiVarId}, barkod={$testMapping->barcode})...\n";
        $r = $bayi->updateStock((string) $bayiVarId, $newStock, $testMapping->barcode);
        echo '    Yanıt: '.json_encode($r)."\n";
    } catch (Throwable $e) {
        echo '  ✗ Stok HATA: '.$e->getMessage()."\n";
    }

    try {
        echo "  → UpdateUrunFiyat (barkod={$testMapping->barcode}, fiyat=620.50)...\n";
        $r = $bayi->updatePrice($testMapping->barcode, 620.50);
        echo '    Yanıt: '.json_encode($r)."\n";
    } catch (Throwable $e) {
        echo '  ✗ Fiyat HATA: '.$e->getMessage()."\n";
    }

    // Doğrula
    $check = $bayi->getProductByBarcode($testMapping->barcode);
    $checkV = is_array($check['Varyasyonlar']) ? ($check['Varyasyonlar']['Varyasyon'] ?? $check['Varyasyonlar']) : [];
    if (isset($checkV['Barkod'])) {
        $checkV = [$checkV];
    }
    echo '  Güncel bayi: stok='.($checkV[0]['StokAdedi'] ?? '?').' fiyat='.($checkV[0]['SatisFiyati'] ?? '?')."\n";
}
