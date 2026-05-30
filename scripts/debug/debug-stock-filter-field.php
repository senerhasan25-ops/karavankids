<?php

/**
 * Stok/fiyat değişim tarihi için DOĞRU filtre alan adını bul.
 * Her adayı 2099 ile dener; filtreliyorsa ~0, yok sayıyorsa 100 döner.
 */

require __DIR__.'/../../vendor/autoload.php';

use App\Services\Ticimax\ProductService;
use Illuminate\Contracts\Console\Kernel;

$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$ana = ProductService::for('ana');
$client = $ana->getClient();

// baseFilter benzeri taban (date alanları MIN/MAX), ama test edilen alanı 2099 yap
function baseDates(): array
{
    $MIN = '0001-01-01T00:00:00';
    $MAX = '9999-12-31T23:59:59';

    return [
        'Aktif' => -1, 'Firsat' => -1, 'Indirimli' => -1,
        'EklemeTarihiBaslangic' => $MIN, 'EklemeTarihiBitis' => $MAX,
        'StokGuncellemeTarihiBaslangic' => $MIN, 'StokGuncellemeTarihiBitis' => $MAX,
        'ResimEklemeTarihiBaslangic' => $MIN, 'ResimEklemeTarihiBitis' => $MAX,
        'YayinTarihiBaslangic' => $MIN, 'YayinTarihiBitis' => $MAX,
    ];
}

function probe($client, array $overrides): int
{
    $f = array_merge(baseDates(), $overrides);
    $params = [
        'UyeKodu' => $client->getUyeKodu(),
        'f' => $f,
        's' => ['BaslangicIndex' => 0, 'KayitSayisi' => 100, 'KayitSayisinaGoreGetir' => true, 'SiralamaDegeri' => 'ID', 'SiralamaYonu' => 'ASC'],
    ];
    $resp = $client->call('product', 'SelectUrun', $params);
    $json = json_decode(json_encode($resp), true);
    $r = $json['SelectUrunResult'] ?? $json;
    $list = $r['UrunKarti'] ?? [];
    if (isset($list['ID'])) {
        $list = [$list];
    }

    return count((array) $list);
}

$F99 = '2099-01-01T00:00:00';
$MAX = '9999-12-31T23:59:59';

$cases = [
    'StokGuncellemeTarihiBaslangic' => ['StokGuncellemeTarihiBaslangic' => $F99, 'StokGuncellemeTarihiBitis' => $MAX],
    'FiyatStokGuncellemeTarihiBas' => ['FiyatStokGuncellemeTarihiBas' => $F99, 'FiyatStokGuncellemeTarihiSon' => $MAX],
    'GuncellemeTarihiBaslangic' => ['GuncellemeTarihiBaslangic' => $F99, 'GuncellemeTarihiBitis' => $MAX],
    'DuzenlemeTarihiBaslangic' => ['DuzenlemeTarihiBaslangic' => $F99, 'DuzenlemeTarihiBitis' => $MAX],
];

echo "Alan adı (2099 ile) → dönen ürün (0 = FİLTRELİYOR ✓, 100 = yok sayılıyor)\n\n";
foreach ($cases as $label => $ov) {
    try {
        $n = probe($client, $ov);
        echo str_pad($label, 34)." → {$n}\n";
    } catch (Throwable $e) {
        echo str_pad($label, 34).' → HATA: '.substr($e->getMessage(), 0, 80)."\n";
    }
}
