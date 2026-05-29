<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Services\Ticimax\TicimaxClient;
use Illuminate\Contracts\Console\Kernel;

$ana = TicimaxClient::for('ana');
$uye = $ana->getUyeKodu();

// Minimal filtre, sadece DateTime'lar zorunlu
$minFilter = [
    'EklemeTarihiBaslangic' => '0001-01-01T00:00:00',
    'EklemeTarihiBitis' => '9999-12-31T23:59:59',
    'StokGuncellemeTarihiBaslangic' => '0001-01-01T00:00:00',
    'StokGuncellemeTarihiBitis' => '9999-12-31T23:59:59',
    'ResimEklemeTarihiBaslangic' => '0001-01-01T00:00:00',
    'ResimEklemeTarihiBitis' => '9999-12-31T23:59:59',
    'YayinTarihiBaslangic' => '0001-01-01T00:00:00',
    'YayinTarihiBitis' => '9999-12-31T23:59:59',
    'DuzenlemeTarihiBaslangic' => '0001-01-01T00:00:00',
    'DuzenlemeTarihiBitis' => '9999-12-31T23:59:59',
];

$call = function (array $extra) use ($ana, $uye, $minFilter) {
    $resp = $ana->call('product', 'SelectUrun', [
        'UyeKodu' => $uye,
        'f' => $extra + $minFilter,
        's' => ['BaslangicIndex' => 0, 'KayitSayisi' => 1000, 'KayitSayisinaGoreGetir' => true],
    ]);
    $list = $resp->SelectUrunResult->UrunKarti ?? [];
    if (! is_array($list)) {
        $list = [$list];
    }

    return count($list);
};

echo "=== Farkli Aktif filtre degerleri ===\n";
foreach ([-1 => 'Hepsi', 1 => 'Sadece aktif', 0 => 'Sadece pasif'] as $val => $label) {
    $n = $call(['Aktif' => $val, 'Firsat' => -1, 'Indirimli' => -1]);
    echo "  Aktif=$val ($label): $n urun\n";
}

echo "\n=== Hicbir Aktif/Firsat/Indirimli filtre vermeden ===\n";
$n = $call([]);
echo "  $n urun\n";

echo "\n=== Cesitli yan filtreler ===\n";
foreach ([
    'PasifResimleriGetir' => true,
    'IlgiliUrunleriListele' => true,
    'KasaOnuFirsatlari' => -1,
    'HediyeIpucuAktif' => false,
] as $k => $v) {
    $n = $call([$k => $v]);
    echo "  $k=".var_export($v, true)." → $n urun\n";
}

echo "\n=== Cok yuksek BaslangicIndex (toplam say) ===\n";
$resp = $ana->call('product', 'SelectUrun', [
    'UyeKodu' => $uye,
    'f' => $minFilter,
    's' => ['BaslangicIndex' => 10000, 'KayitSayisi' => 1, 'KayitSayisinaGoreGetir' => true],
]);
$list = $resp->SelectUrunResult->UrunKarti ?? [];
if (! is_array($list)) {
    $list = [$list];
}
echo '  10000+ index: '.count($list)." urun\n";

// Sayfa sayfa total say (1000'er)
echo "\n=== Toplam (1000'er sayfalama) ===\n";
$page = 0;
$total = 0;
while ($page < 50) {
    $resp = $ana->call('product', 'SelectUrun', [
        'UyeKodu' => $uye,
        'f' => $minFilter,
        's' => ['BaslangicIndex' => $page * 1000, 'KayitSayisi' => 1000, 'KayitSayisinaGoreGetir' => true],
    ]);
    $list = $resp->SelectUrunResult->UrunKarti ?? [];
    if (! is_array($list)) {
        $list = [$list];
    }
    $c = count($list);
    $total += $c;
    echo '  index '.($page * 1000).": $c urun (toplam $total)\n";
    if ($c < 1000) {
        break;
    }
    $page++;
}
echo "\nFINAL: ana magazada toplam $total urun gorunuyor (api uzerinden)\n";
