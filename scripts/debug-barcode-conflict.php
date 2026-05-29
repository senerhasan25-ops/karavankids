<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Services\Ticimax\ProductService;
use Illuminate\Contracts\Console\Kernel;

$barkod = $argv[1] ?? '636997227832';

$bayi = ProductService::for('bayi');
echo "=== Bayi'de barkod {$barkod} aranıyor ===\n";

$existing = $bayi->getProductByBarcode($barkod);
if ($existing) {
    echo "EVET — BAYI'DE VAR\n";
    echo 'UrunKartiID: '.($existing['ID'] ?? '?')."\n";
    echo 'UrunAdi: '.($existing['UrunAdi'] ?? '?')."\n";
    echo "TedarikciKodu (UrunKarti): '".($existing['TedarikciKodu'] ?? '(boş)')."'\n";

    $v = $existing['Varyasyonlar']['Varyasyon'] ?? null;
    if (is_array($v) && ! array_is_list($v)) {
        $v = [$v];
    }
    if ($v && isset($v[0])) {
        echo "Varyasyon TedarikciKodu: '".($v[0]['TedarikciKodu'] ?? '(boş)')."'\n";
        echo "Varyasyon StokKodu: '".($v[0]['StokKodu'] ?? '(boş)')."'\n";
        echo 'Varyasyon ID: '.($v[0]['ID'] ?? '?')."\n";
    }

    echo "\n--- Yorum ---\n";
    $expectedTedKodu = 'SUP|';
    $currentTedKodu = (string) ($existing['TedarikciKodu'] ?? '');
    if (! str_starts_with($currentTedKodu, $expectedTedKodu)) {
        echo "❗ Bayi'deki ürünün TedarikciKodu'su 'SUP|' ile başlamıyor (Hasan'ın eski sync'inden veya manuel olarak).\n";
        echo "   Bu yüzden TedarikciKodunaGoreGuncelle:true ana'nın SUP|... kodu ile eşleşmiyor →\n";
        echo "   Ticimax yeni ürün yaratmaya çalışıyor → barkod çakışıyor → SaveUrunResult=0.\n";
        echo "   ÇÖZÜM: bu ürüne TedarikciKodu = SUP|{anaId}|{stokKodu} elle yazıp güncellemek\n";
        echo "          VEYA Bayi tarafındaki eski ürünleri silip baştan sync etmek.\n";
    }
} else {
    echo "HAYIR — bayi'de bulunamadı. Demek gerçek yeni ürün.\n";
    echo "Bu durumda hata sebebi başka: zorunlu alan eksik veya test ortamı limiti.\n";
}
