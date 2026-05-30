<?php

/**
 * Ana'da 3653 panel ürünü vs 3138 SelectUrun farkı nereden?
 * Farklı filtre kombinasyonlarında distinct kart sayısını ölç.
 */

require __DIR__.'/../../vendor/autoload.php';

use App\Services\Ticimax\ProductService;
use Illuminate\Contracts\Console\Kernel;

$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$store = $argv[1] ?? 'ana';
$svc = ProductService::for($store);
$client = $svc->getClient();

$MIN = '0001-01-01T00:00:00';
$MAX = '9999-12-31T23:59:59';

/** Verilen filtre ile tüm sayfaları gez, distinct kart ID döndür. */
function countDistinct($client, array $fOverrides): array
{
    $perPage = 100;
    $page = 1;
    $distinct = [];
    $bug = 0;
    $consecutiveBug = 0;
    while ($page <= 200) {
        $f = array_merge([
            'Aktif' => -1, 'Firsat' => -1, 'Indirimli' => -1,
            'EklemeTarihiBaslangic' => '0001-01-01T00:00:00', 'EklemeTarihiBitis' => '9999-12-31T23:59:59',
            'StokGuncellemeTarihiBaslangic' => '0001-01-01T00:00:00', 'StokGuncellemeTarihiBitis' => '9999-12-31T23:59:59',
            'ResimEklemeTarihiBaslangic' => '0001-01-01T00:00:00', 'ResimEklemeTarihiBitis' => '9999-12-31T23:59:59',
            'YayinTarihiBaslangic' => '0001-01-01T00:00:00', 'YayinTarihiBitis' => '9999-12-31T23:59:59',
        ], $fOverrides);
        $params = [
            'UyeKodu' => $client->getUyeKodu(),
            'f' => $f,
            's' => ['BaslangicIndex' => ($page - 1) * $perPage, 'KayitSayisi' => $perPage, 'KayitSayisinaGoreGetir' => true, 'SiralamaDegeri' => 'ID', 'SiralamaYonu' => 'ASC'],
        ];
        try {
            $resp = $client->call('product', 'SelectUrun', $params);
        } catch (Throwable $e) {
            if (stripos($e->getMessage(), 'Value cannot be null') !== false) {
                $bug++;
                $consecutiveBug++;
                if ($consecutiveBug >= 10) {
                    break;
                }
                $page++;

                continue;
            }
            throw $e;
        }
        $consecutiveBug = 0;
        $json = json_decode(json_encode($resp), true);
        $r = $json['SelectUrunResult'] ?? $json;
        $list = $r['UrunKarti'] ?? [];
        if (isset($list['ID'])) {
            $list = [$list];
        }
        $n = count((array) $list);
        foreach ((array) $list as $p) {
            $id = (int) ($p['ID'] ?? 0);
            if ($id > 0) {
                $distinct[$id] = true;
            }
        }
        if ($n < $perPage) {
            break;
        }
        $page++;
    }

    return ['distinct' => count($distinct), 'bug' => $bug];
}

$cases = [
    'Aktif=-1 (hepsi)' => ['Aktif' => -1],
    'Aktif=1 (aktif)' => ['Aktif' => 1],
    'Aktif=0 (pasif)' => ['Aktif' => 0],
];

echo "=== {$store} distinct kart sayımı (filtreye göre) ===\n";
foreach ($cases as $label => $ov) {
    $r = countDistinct($client, $ov);
    echo str_pad($label, 22)." → distinct={$r['distinct']} (bug sayfa={$r['bug']})\n";
}
