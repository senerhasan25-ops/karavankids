<?php

/**
 * Sipariş Servisi WSDL doğrulama scripti.
 *
 * Hasan'ın ürün tarafında yaptığının aynısı: gerçek Ticimax SOAP endpoint'ine
 * bağlanıp config/ticimax.php'deki sipariş method isimlerinin gerçekten var
 * olduğunu, hangi parametreleri beklediklerini gösterir.
 *
 * Kullanım:
 *   php scripts/debug-order-wsdl.php           # bayi store
 *   php scripts/debug-order-wsdl.php ana       # ana store
 */

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Services\Ticimax\TicimaxClient;
use Illuminate\Contracts\Console\Kernel;

$store = $argv[1] ?? 'bayi';
echo "=== Sipariş WSDL doğrulama — store: {$store} ===\n\n";

try {
    $client = TicimaxClient::for($store);
    $soap = $client->client('order');
} catch (Throwable $e) {
    echo 'BAĞLANTI HATASI: '.$e->getMessage()."\n";
    exit(1);
}

// 1) Config'teki method isimleri
$configured = config('ticimax.methods.order');
echo "Config'teki method isimleri:\n";
foreach ($configured as $key => $name) {
    echo "  {$key} => {$name}\n";
}
echo "\n";

// 2) WSDL'deki gerçek fonksiyonlar
$functions = $soap->__getFunctions();
echo "WSDL'deki toplam fonksiyon sayısı: ".count($functions)."\n\n";

// Method ismi → tam imza haritası
$signatures = [];
foreach ($functions as $f) {
    if (preg_match('/\s(\w+)\s*\(/', $f, $m)) {
        $signatures[$m[1]] = $f;
    }
}

// 3) Config'teki her method için WSDL'de var mı kontrol et
echo "--- Eşleşme kontrolü ---\n";
$missing = [];
foreach ($configured as $key => $name) {
    if (isset($signatures[$name])) {
        echo "✓ {$key} ({$name}) — VAR\n";
        echo '    '.$signatures[$name]."\n";
    } else {
        echo "✗ {$key} ({$name}) — YOK!\n";
        $missing[] = $name;

        // Benzer isim öner
        $similar = array_filter(array_keys($signatures), fn ($s) => stripos($s, str_replace(['Set', 'Select', 'Save'], '', $name)) !== false || stripos($s, substr($name, 0, 7)) !== false);
        if ($similar) {
            echo '    Benzer isimler: '.implode(', ', array_slice($similar, 0, 8))."\n";
        }
    }
}

// 4) WSDL'de "Siparis" geçen tüm fonksiyonları listele (manuel inceleme için)
echo "\n--- WSDL'deki tüm 'Siparis' içeren fonksiyonlar ---\n";
$siparisFns = array_filter(array_keys($signatures), fn ($s) => stripos($s, 'Siparis') !== false);
sort($siparisFns);
foreach ($siparisFns as $fn) {
    echo '  • '.$signatures[$fn]."\n";
}

// 5) Eksik method yoksa, sipariş tiplerinin field'larını dök
if (empty($missing)) {
    echo "\n--- Sipariş tipi (Complex Types) ---\n";
    $types = $soap->__getTypes();
    foreach ($types as $t) {
        // Sadece sipariş + alt nesnelerini göster
        if (preg_match('/struct (Siparis|SiparisFiltre|SiparisUrun|SiparisAdres|SiparisUye)\b/i', $t)) {
            echo $t."\n\n";
        }
    }
}

echo "\n--- Sonuç ---\n";
if (empty($missing)) {
    echo "✓ Tüm method isimleri WSDL ile eşleşiyor.\n";
} else {
    echo '✗ '.count($missing)." adet method WSDL'de yok: ".implode(', ', $missing)."\n";
    echo "  config/ticimax.php → methods.order altını güncelle.\n";
}
