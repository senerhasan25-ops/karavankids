<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Services\Ticimax\ProductMapper;
use App\Services\Ticimax\ProductService;
use App\Services\Ticimax\TicimaxClient;
use Illuminate\Contracts\Console\Kernel;

$barcode = $argv[1] ?? 'Mg26Winter1491';
echo "=== Raw SOAP debug for {$barcode} ===\n";

$ana = ProductService::for('ana');
$bayi = ProductService::for('bayi');
$mapper = new ProductMapper;
$mapper->setBrandResolver(fn (string $name) => $bayi->findOrCreateBrandId($name));
$anaSupplierIdToName = array_flip($ana->getSupplierMap());
$mapper->setSupplierResolver(function (int $anaId) use ($anaSupplierIdToName, $bayi) {
    $name = $anaSupplierIdToName[$anaId] ?? '';

    return $name ? $bayi->findOrCreateSupplierId($name) : 0;
});

$anaUrun = $ana->getProductByBarcode($barcode);
if (! $anaUrun) {
    echo "ANA'da yok\n";
    exit(1);
}
echo "Ana ID: {$anaUrun['ID']}\n";

$payload = $mapper->anaToBayiCreatePayload($anaUrun);

// Ham SOAP client kullan
$bayiClient = TicimaxClient::for('bayi');
$soap = $bayiClient->client('product');

$ayarlar = [
    'ukAyar' => [
        'AciklamaGuncelle' => true, 'AktifGuncelle' => true,
        'AnaKategoriGuncelle' => false, 'AramaAnahtarKelimeGuncelle' => true,
        'EtiketGuncelle' => false, 'KategoriGuncelle' => false,
        'ListedeGosterGuncelle' => true, 'MarkaGuncelle' => true,
        'OnYaziGuncelle' => true, 'ResimOlmayanlaraResimEkle' => true,
        'SatisBirimiGuncelle' => true, 'SeoAnahtarKelimeGuncelle' => true,
        'SeoSayfaAciklamaGuncelle' => true, 'SeoSayfaBaslikGuncelle' => true,
        'OncekiResimleriSil' => false, 'Base64Resim' => false, 'ResimleriIndirme' => false,
    ],
    'vAyar' => [
        'AktifGuncelle' => true, 'AlisFiyatiGuncelle' => true, 'BarkodGuncelle' => true,
        'IndirimliFiyatiGuncelle' => true, 'KdvDahilGuncelle' => true, 'KdvOraniGuncelle' => true,
        'ParaBirimiGuncelle' => true, 'PiyasaFiyatiGuncelle' => true,
        'SatisFiyatiGuncelle' => true, 'StokAdediGuncelle' => true, 'StokKoduGuncelle' => true,
        'UrunKartiAktifGuncelle' => true, 'OncekiResimleriSil' => false,
    ],
];

$params = [
    'UyeKodu' => $bayiClient->getUyeKodu(),
    'urunKartlari' => [$payload],
    'ukAyar' => $ayarlar['ukAyar'],
    'vAyar' => $ayarlar['vAyar'],
];

echo "\n--- SaveUrun çağrılıyor ---\n";
try {
    $resp = $soap->__soapCall('SaveUrun', [$params]);
    echo "Response objesi:\n";
    var_export($resp);
    echo "\n\n--- Last Response XML (ilk 5000 char) ---\n";
    $xml = $soap->__getLastResponse();
    echo substr($xml, 0, 5000)."\n";
} catch (Throwable $e) {
    echo 'EXCEPTION: '.$e->getMessage()."\n";
    echo "Last Response:\n".substr($soap->__getLastResponse() ?? '', 0, 5000)."\n";
}
