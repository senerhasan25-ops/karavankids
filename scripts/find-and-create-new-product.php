<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\Ticimax\ProductMapper;
use App\Services\Ticimax\ProductService;

$ana = ProductService::for('ana');
$bayi = ProductService::for('bayi');

echo "=== Ana'da olup bayi'de olmayan ilk ürün aranıyor ===\n";

$found = null;
$scanned = 0;
$page = 1;

// Yeni ürünler ID DESC tarafından gelir
scan: while ($page <= 50) {
    $products = $ana->getNewProducts(null, $page, 25, 'DESC');
    if (empty($products)) {
        break;
    }
    foreach ($products as $urun) {
        $scanned++;
        $varList = $urun['Varyasyonlar'] ?? [];
        if (isset($varList['Varyasyon'])) {
            $varList = is_array($varList['Varyasyon']) && array_is_list($varList['Varyasyon']) ? $varList['Varyasyon'] : [$varList['Varyasyon']];
        }
        if (! is_array($varList) || empty($varList)) {
            continue;
        }
        $primaryBarcode = $varList[0]['Barkod'] ?? null;
        if (! $primaryBarcode) {
            continue;
        }
        // "Mg" prefix'li test verilerini atla
        if (str_starts_with($primaryBarcode, 'Mg')) {
            continue;
        }

        $bayiCheck = $bayi->getProductByBarcode($primaryBarcode);
        if (! $bayiCheck) {
            $found = $urun;
            echo "  ✓ Bulundu! barkod={$primaryBarcode} | ana_ID={$urun['ID']} | {$urun['UrunAdi']}\n";
            break 2;
        }
        if ($scanned % 10 === 0) {
            echo "  ... taranan: {$scanned} (hepsi bayi'de var)\n";
        }
    }
    $page++;
}

if (! $found) {
    echo "Ana'nın ilk " . (($page - 1) * 25) . " ürününde bayi'de olmayan bulunamadı. Sayfa aralığını genişlet.\n";
    exit(1);
}

echo "\n=== 2) Ürün detayı ===\n";
$varList = $found['Varyasyonlar']['Varyasyon'] ?? $found['Varyasyonlar'];
if (isset($varList['Barkod'])) { $varList = [$varList]; }
$primary = $varList[0];
echo "  Ana UrunKartiID: {$found['ID']}\n";
echo "  Ürün Adı: {$found['UrunAdi']}\n";
echo "  Marka: " . ($found['Marka'] ?? '-') . "\n";
echo "  Tedarikçi: ana ID=" . ($found['TedarikciID'] ?? 0) . "\n";
echo "  Aktif: " . (! empty($found['Aktif']) ? 'evet' : 'hayır') . "\n";
echo "  Varyasyon sayısı: " . count($varList) . "\n";
echo "  Birincil barkod: {$primary['Barkod']} | stok={$primary['StokAdedi']} | fiyat={$primary['SatisFiyati']} | ParaBirimi={$primary['ParaBirimi']} ID={$primary['ParaBirimiID']}\n";

echo "\n=== 3) Mapper payload üret ===\n";
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

$payload = $mapper->anaToBayiCreatePayload($found);
echo "  Payload UrunAdi: {$payload['UrunAdi']}\n";
echo "  Payload Marka: " . ($payload['Marka'] ?: '(boş)') . " (ID={$payload['MarkaID']})\n";
echo "  Payload TedarikciID: {$payload['TedarikciID']}\n";
echo "  Varyasyon[0]: barkod={$payload['Varyasyonlar'][0]['Barkod']} stok={$payload['Varyasyonlar'][0]['StokAdedi']} fiyat={$payload['Varyasyonlar'][0]['SatisFiyati']} ParaBirimiID={$payload['Varyasyonlar'][0]['ParaBirimiID']}\n";

echo "\n=== 4) Bayi'ye SaveUrun ===\n";
try {
    $created = $bayi->createProduct($payload);
    $newId = (int) ($created['ID'] ?? 0);

    if ($newId === 0) {
        echo "  SaveUrun ID=0 döndü, barkodla yeniden çekiliyor...\n";
        $verify = $bayi->getProductByBarcode($primary['Barkod']);
        $newId = (int) ($verify['ID'] ?? 0);
    }

    if ($newId > 0) {
        echo "  ✓ BAŞARILI! Bayi'de yeni ürün ID={$newId}\n";

        // Doğrula
        $check = $bayi->getProductByBarcode($primary['Barkod']);
        if ($check) {
            $checkVar = $check['Varyasyonlar']['Varyasyon'] ?? $check['Varyasyonlar'];
            if (isset($checkVar['Barkod'])) { $checkVar = [$checkVar]; }
            $cv = $checkVar[0];
            echo "  Bayi'deki kayıt:\n";
            echo "    ID={$check['ID']} | Aktif=" . (! empty($check['Aktif']) ? 'evet' : 'hayır') . "\n";
            echo "    UrunAdi: {$check['UrunAdi']}\n";
            echo "    Marka: " . ($check['Marka'] ?? '-') . " (MarkaID={$check['MarkaID']})\n";
            echo "    TedarikciID: " . ($check['TedarikciID'] ?? '-') . "\n";
            echo "    Birincil varyasyon: barkod={$cv['Barkod']} stok={$cv['StokAdedi']} fiyat={$cv['SatisFiyati']} ID={$cv['ID']}\n";
        }
    } else {
        echo "  ✗ Yine 0 döndü, bir sorun var\n";
    }
} catch (\Throwable $e) {
    echo "  ✗ HATA: " . $e->getMessage() . "\n";
}
