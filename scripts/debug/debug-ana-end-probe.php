<?php

/**
 * Ana'da 3138'den fazla ürün var mı? DESC ile en yüksek ID, ve yüksek offset'lerde
 * veri devam ediyor mu (gerçek son mu, yoksa SelectUrun bir yerde kesiyor mu)?
 */

require __DIR__.'/../../vendor/autoload.php';

use App\Services\Ticimax\ProductService;
use Illuminate\Contracts\Console\Kernel;

$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$svc = ProductService::for($argv[1] ?? 'ana');
$client = $svc->getClient();

function call($client, int $startIdx, int $perPage, string $dir): array
{
    $MIN = '0001-01-01T00:00:00';
    $MAX = '9999-12-31T23:59:59';
    $f = [
        'Aktif' => -1, 'Firsat' => -1, 'Indirimli' => -1,
        'EklemeTarihiBaslangic' => $MIN, 'EklemeTarihiBitis' => $MAX,
        'StokGuncellemeTarihiBaslangic' => $MIN, 'StokGuncellemeTarihiBitis' => $MAX,
        'ResimEklemeTarihiBaslangic' => $MIN, 'ResimEklemeTarihiBitis' => $MAX,
        'YayinTarihiBaslangic' => $MIN, 'YayinTarihiBitis' => $MAX,
    ];
    $params = [
        'UyeKodu' => $client->getUyeKodu(),
        'f' => $f,
        's' => ['BaslangicIndex' => $startIdx, 'KayitSayisi' => $perPage, 'KayitSayisinaGoreGetir' => true, 'SiralamaDegeri' => 'ID', 'SiralamaYonu' => $dir],
    ];
    try {
        $resp = $client->call('product', 'SelectUrun', $params);
    } catch (Throwable $e) {
        return ['err' => substr($e->getMessage(), 0, 60)];
    }
    $json = json_decode(json_encode($resp), true);
    $list = $json['SelectUrunResult']['UrunKarti'] ?? [];
    if (isset($list['ID'])) {
        $list = [$list];
    }
    $ids = array_map(fn ($p) => (int) ($p['ID'] ?? 0), (array) $list);

    return ['n' => count($ids), 'min' => $ids ? min($ids) : null, 'max' => $ids ? max($ids) : null];
}

echo "ASC ilk sayfa (en düşük ID): ".json_encode(call($client, 0, 5, 'ASC'))."\n";
echo "DESC ilk sayfa (en yüksek ID): ".json_encode(call($client, 0, 5, 'DESC'))."\n\n";

echo "Yüksek offset'lerde veri devam ediyor mu? (ASC)\n";
foreach ([3000, 3100, 3138, 3200, 3400, 3600, 4000] as $off) {
    echo "  offset {$off}: ".json_encode(call($client, $off, 10, 'ASC'))."\n";
}
