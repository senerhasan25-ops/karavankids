<?php

/**
 * Gemini'nin Python yaklaşımı: f sadece {Aktif:1}, tarih alanı YOK.
 * Bizim baseFilter() ise tarih alanlarını MIN/MAX ile gönderiyor → NULL tarihli
 * ürünler eleniyor olabilir. İkisini sayfalayıp say, farkı gör.
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

function say($client, array $f, string $label): void
{
    $perPage = 100;
    $page = 1;
    $distinct = [];
    $bug = 0;
    $consecBug = 0;
    while ($page <= 200) {
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
                $consecBug++;
                if ($consecBug >= 10) {
                    break;
                }
                $page++;

                continue;
            }
            throw $e;
        }
        $consecBug = 0;
        $json = json_decode(json_encode($resp), true);
        $list = $json['SelectUrunResult']['UrunKarti'] ?? [];
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
    echo str_pad($label, 46).' → distinct='.count($distinct)." (bug sayfa={$bug})\n";
}

echo "=== {$store} — filtre karşılaştırması ===\n";

// 1) Python: SADECE Aktif:1
say($client, ['Aktif' => 1], 'Python: {Aktif:1} (tarih alanı YOK)');

// 2) Python: SADECE Aktif:-1
say($client, ['Aktif' => -1], 'Minimal: {Aktif:-1} (tarih alanı YOK)');

// 3) Bizim baseFilter (tarih MIN/MAX dahil), Aktif:-1
say($client, [
    'Aktif' => -1, 'Firsat' => -1, 'Indirimli' => -1,
    'EklemeTarihiBaslangic' => $MIN, 'EklemeTarihiBitis' => $MAX,
    'StokGuncellemeTarihiBaslangic' => $MIN, 'StokGuncellemeTarihiBitis' => $MAX,
    'ResimEklemeTarihiBaslangic' => $MIN, 'ResimEklemeTarihiBitis' => $MAX,
    'YayinTarihiBaslangic' => $MIN, 'YayinTarihiBitis' => $MAX,
], 'Bizim baseFilter (tüm tarih MIN/MAX)');

// 4) Tek tek hangi tarih alanı eliyor? Her birini tek başına ekle (Aktif:-1)
foreach ([
    'EklemeTarihi' => ['EklemeTarihiBaslangic' => $MIN, 'EklemeTarihiBitis' => $MAX],
    'StokGuncellemeTarihi' => ['StokGuncellemeTarihiBaslangic' => $MIN, 'StokGuncellemeTarihiBitis' => $MAX],
    'ResimEklemeTarihi' => ['ResimEklemeTarihiBaslangic' => $MIN, 'ResimEklemeTarihiBitis' => $MAX],
    'YayinTarihi' => ['YayinTarihiBaslangic' => $MIN, 'YayinTarihiBitis' => $MAX],
] as $name => $extra) {
    say($client, array_merge(['Aktif' => -1], $extra), "Sadece {$name} MIN/MAX ekli");
}
