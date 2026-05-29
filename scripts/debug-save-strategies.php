<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Services\Ticimax\ProductMapper;
use App\Services\Ticimax\ProductService;
use Illuminate\Contracts\Console\Kernel;

$ana = ProductService::for('ana');
$bayi = ProductService::for('bayi');
$mapper = new ProductMapper;
$mapper->setBrandResolver(fn (string $name) => $bayi->findOrCreateBrandId($name));
$anaSupplierIdToName = array_flip($ana->getSupplierMap());
$mapper->setSupplierResolver(function (int $anaId) use ($anaSupplierIdToName, $bayi) {
    $name = $anaSupplierIdToName[$anaId] ?? '';

    return $name ? $bayi->findOrCreateSupplierId($name) : 0;
});

// Test ürünü
$barcode = 'Mg26Winter1491';
$anaUrun = $ana->getProductByBarcode($barcode);
if (! $anaUrun) {
    echo "Ana'da yok\n";
    exit(1);
}
echo "Test ürünü: {$anaUrun['UrunAdi']} (barkod={$barcode})\n\n";

// === STRATEJİ 1: Minimal payload ===
echo "=== Strateji 1: Minimal payload (sadece UrunAdi+Barkod+Stok+Fiyat) ===\n";
$minimal = [
    'ID' => 0,
    'Aktif' => true,
    'UrunAdi' => $anaUrun['UrunAdi'],
    'Aciklama' => '',
    'OnYazi' => '',
    'AramaAnahtarKelime' => '',
    'AnaKategori' => '',
    'AnaKategoriID' => 0,
    'Kategoriler' => [],
    'Marka' => '',
    'MarkaID' => 0,
    'SeoSayfaBaslik' => $anaUrun['UrunAdi'],
    'SeoSayfaAciklama' => '',
    'SeoAnahtarKelime' => '',
    'UrunSayfaAdresi' => '',
    'ListedeGoster' => true,
    'Vitrin' => false,
    'YeniUrun' => false,
    'TedarikciID' => 0,
    'TedarikciKodu' => '',
    'TedarikciKodu2' => '',
    'Resimler' => [],
    'Varyasyonlar' => [[
        'ID' => 0, 'UrunKartiID' => 0, 'Aktif' => true,
        'Barkod' => $barcode, 'StokKodu' => $barcode,
        'StokAdedi' => 5.0, 'SatisFiyati' => 100.0, 'IndirimliFiyati' => 0,
        'AlisFiyati' => 0, 'PiyasaFiyati' => 0,
        'KdvOrani' => 20.0, 'KdvDahil' => true,
        'ParaBirimi' => 'TL', 'ParaBirimiID' => 1,
        'Desi' => 0, 'UrunAgirligi' => 0,
        'Ozellikler' => [], 'Resimler' => [],
    ]],
];
testSave($bayi, $minimal, 'Minimal');

// === STRATEJİ 2: Mevcut marka (Meri Meri = bayi ID 3) ===
echo "\n=== Strateji 2: Var olan markayla (Meri Meri) ===\n";
$brandTest = $minimal;
$brandTest['Marka'] = 'Meri Meri';
$brandTest['MarkaID'] = 3;
testSave($bayi, $brandTest, 'WithExistingBrand');

// === STRATEJİ 3: Test barkodu (Ticimax test mağazasında zaten yoktur)
echo "\n=== Strateji 3: Tamamen yeni barkodlu test ürünü ===\n";
$newBar = 'TEST-'.date('YmdHis');
$brandTest2 = $brandTest;
$brandTest2['UrunAdi'] = 'TEST ÜRÜNÜ — Karavankids Sync E2E';
$brandTest2['Varyasyonlar'][0]['Barkod'] = $newBar;
$brandTest2['Varyasyonlar'][0]['StokKodu'] = $newBar;
testSave($bayi, $brandTest2, 'NewTestBarcode');

// === STRATEJİ 4: Full mapper payload ===
echo "\n=== Strateji 4: Full mapper payload ===\n";
$fullPayload = $mapper->anaToBayiCreatePayload($anaUrun);
testSave($bayi, $fullPayload, 'FullMapper');

function testSave(ProductService $bayi, array $payload, string $name): void
{
    try {
        $result = $bayi->createProduct($payload);
        $newId = (int) ($result['ID'] ?? 0);
        if ($newId > 0) {
            echo "  ✓ {$name}: BAŞARILI! ID={$newId}\n";
        } else {
            // Belki SaveUrunResult'da gerçek ID döner
            echo "  ⚠ {$name}: ID=0 ama exception yok (saveBatch'ten ne döndü?)\n";
        }
    } catch (Throwable $e) {
        $msg = substr($e->getMessage(), 0, 200);
        echo "  ✗ {$name}: {$msg}\n";
    }
}
