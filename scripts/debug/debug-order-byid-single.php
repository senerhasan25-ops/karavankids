<?php

/**
 * SiparisID ile tek-çağrı testi (perf düzeltmesi A doğrulaması).
 *
 * Soru: SiparisID dolu + SiparisDurumu=-1 ile TEK SOAP çağrısı siparişi
 * döndürüyor mu? Döndürüyorsa getOrderById'yi 23-çağrılı Hepsi modundan
 * çıkarıp tek çağrıya indirebiliriz.
 *
 * Kullanım: php scripts/debug/debug-order-byid-single.php bayi
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';

use App\Services\Ticimax\OrderService;
use Illuminate\Contracts\Console\Kernel;

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$store = $argv[1] ?? 'bayi';
echo "=== SiparisID tek-çağrı testi — store: {$store} ===\n\n";

$svc = OrderService::for($store);

// 1) Hepsi modundan son 30 günden bir sipariş bul (referans ID).
echo "[1] Referans sipariş aranıyor (son 30 gün, Hepsi)...\n";
$t0 = microtime(true);
$list = $svc->getOrdersByFilter([
    'date_from' => now()->subDays(30)->format('Y-m-d'),
    'date_to' => now()->format('Y-m-d'),
    'siparis_durumu' => -1,
], 1, 5);
$dtList = round((microtime(true) - $t0) * 1000);
echo '    Hepsi modu '.count($list)." sipariş, {$dtList}ms\n";

if (empty($list)) {
    echo "    Sipariş bulunamadı, test edilemiyor.\n";
    exit(1);
}

$id = (int) ($list[0]['ID'] ?? $list[0]['SiparisID'] ?? 0);
echo "    Referans SiparisID = {$id}\n\n";

// 2) Aynı ID'yi SiparisDurumu=-1 ile TEK çağrı dener (mevcut getOrdersByFilter
//    siparis_id verince yine Hepsi moduna giriyor — burada ham client ile tek
//    çağrı simüle edelim).
echo "[2] SiparisID={$id} + SiparisDurumu=-1 TEK çağrı...\n";
$client = $svc->getClient();
$method = 'SelectSiparis';
$params = [
    'UyeKodu' => $client->getUyeKodu(),
    'f' => [
        'DuzenlemeTarihiBas' => null,
        'DuzenlemeTarihiSon' => null,
        'EntegrasyonAktarildi' => -1,
        'EntegrasyonParams' => ['EntegrasyonParamsAktif' => false],
        'IptalEdilmisUrunler' => false,
        'KampanyaGetir' => false,
        'KargoFirmaID' => 0,
        'OdemeDurumu' => -1,
        'OdemeTipi' => -1,
        'PaketlemeDurumu' => -1,
        'SiparisDurumu' => -1,
        'SiparisID' => $id,
        'SiparisKaynagi' => '',
        'SiparisNo' => null,
        'SiparisTarihiBas' => now()->subYear()->format('Y-m-d\T00:00:00'),
        'SiparisTarihiSon' => now()->format('Y-m-d\T23:59:59'),
        'StrSiparisDurumu' => '',
        'TedarikciID' => -1,
        'UrunGetir' => true,
        'UyeID' => -1,
        'UyeTelefon' => null,
    ],
    's' => ['BaslangicIndex' => 0, 'KayitSayisi' => 1, 'SiralamaYonu' => 'DESC'],
];
$t1 = microtime(true);
$resp = $client->call('order', $method, $params);
$dtSingle = round((microtime(true) - $t1) * 1000);

// normalizeList private; basitçe yanıtı say.
$json = json_decode(json_encode($resp), true);
$found = json_encode($json);
$hit = strpos($found, '"ID":'.$id) !== false || strpos($found, '"SiparisID":'.$id) !== false || strpos($found, (string) $id) !== false;

echo "    Tek çağrı {$dtSingle}ms, sipariş döndü mü? ".($hit ? 'EVET ✓' : 'HAYIR ✗')."\n\n";

echo "SONUÇ: ".($hit
    ? "SiparisDurumu=-1 + SiparisID ile TEK çağrı çalışıyor. A güvenli.\n"
    : "TEK çağrı boş döndü — SiparisDurumu=-1 burada da sorun. A için farklı yaklaşım gerek.\n");
