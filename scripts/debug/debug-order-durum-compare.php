<?php

/**
 * SiparisDurumu=-1 tek çağrı, 23-loop Hepsi ile AYNI sipariş kümesini mi döndürüyor?
 * Top-level sipariş ID'lerini iki yöntemden çıkarıp karşılaştırır.
 *
 * Kullanım: php scripts/debug/debug-order-durum-compare.php bayi
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';

use App\Services\Ticimax\OrderService;
use Illuminate\Contracts\Console\Kernel;

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$store = $argv[1] ?? 'bayi';
$svc = OrderService::for($store);
$client = $svc->getClient();

$bas = now()->subDays(90)->format('Y-m-d\T00:00:00');
$son = now()->format('Y-m-d\T23:59:59');

function topLevelOrderIds($resp): array
{
    $json = json_decode(json_encode($resp), true);
    // SelectSiparisResult → Siparisler → WebSiparis[]
    $r = $json['SelectSiparisResult'] ?? $json;
    $list = $r['WebSiparis'] ?? [];
    if (isset($list['ID']) || isset($list['SiparisNo'])) {
        $list = [$list]; // tek kayıt
    }
    $ids = [];
    foreach ((array) $list as $o) {
        $id = $o['ID'] ?? $o['SiparisID'] ?? null;
        if ($id !== null) {
            $ids[] = (string) $id;
        }
    }

    return $ids;
}

function buildParams($client, $bas, $son, int $durum): array
{
    return [
        'UyeKodu' => $client->getUyeKodu(),
        'f' => [
            'DuzenlemeTarihiBas' => null, 'DuzenlemeTarihiSon' => null,
            'EntegrasyonAktarildi' => -1,
            'EntegrasyonParams' => ['EntegrasyonParamsAktif' => false],
            'IptalEdilmisUrunler' => false, 'KampanyaGetir' => false, 'KargoFirmaID' => 0,
            'OdemeDurumu' => -1, 'OdemeTipi' => -1, 'PaketlemeDurumu' => -1,
            'SiparisDurumu' => $durum, 'SiparisID' => 0, 'SiparisKaynagi' => '',
            'SiparisNo' => null, 'SiparisTarihiBas' => $bas, 'SiparisTarihiSon' => $son,
            'StrSiparisDurumu' => '', 'TedarikciID' => -1, 'UrunGetir' => false,
            'UyeID' => -1, 'UyeTelefon' => null,
        ],
        's' => ['BaslangicIndex' => 0, 'KayitSayisi' => 500, 'SiralamaYonu' => 'DESC'],
    ];
}

echo "=== -1 tek çağrı vs 23-loop karşılaştırma — {$store} (son 90 gün) ===\n\n";

// Tek -1 çağrısı
$resp = $client->call('order', 'SelectSiparis', buildParams($client, $bas, $son, -1));
$single = array_values(array_unique(topLevelOrderIds($resp)));
sort($single);
echo '[-1 tek çağrı] '.count($single)." benzersiz sipariş: ".implode(',', $single)."\n\n";

// 23-loop union
$union = [];
foreach (range(0, 22) as $d) {
    $r = $client->call('order', 'SelectSiparis', buildParams($client, $bas, $son, $d));
    foreach (topLevelOrderIds($r) as $id) {
        $union[$id] = true;
    }
}
$union = array_keys($union);
sort($union);
echo '[23-loop union] '.count($union)." benzersiz sipariş: ".implode(',', $union)."\n\n";

$onlySingle = array_diff($single, $union);
$onlyUnion = array_diff($union, $single);
echo 'Sadece -1\'de olanlar : '.($onlySingle ? implode(',', $onlySingle) : '(yok)')."\n";
echo 'Sadece loop\'ta olanlar: '.($onlyUnion ? implode(',', $onlyUnion) : '(yok)')."\n\n";

echo empty($onlyUnion)
    ? "SONUÇ: -1 tek çağrı loop'un TÜM siparişlerini içeriyor. D güvenli (tek çağrı yeterli).\n"
    : "SONUÇ: -1 bazı siparişleri KAÇIRIYOR — 23-loop gerekli. D'yi farklı yapmalı.\n";
