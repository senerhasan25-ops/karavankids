<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Services\Ticimax\ProductService;
use App\Services\Ticimax\TicimaxClient;
use Illuminate\Contracts\Console\Kernel;

$ana = TicimaxClient::for('ana');
$resp = $ana->call('product', 'SelectKategori', ['UyeKodu' => $ana->getUyeKodu()]);
$list = $resp->SelectKategoriResult->Kategori ?? [];
if (! is_array($list)) {
    $list = [$list];
}
echo 'Ana kategori sayisi: '.count($list)."\n";
echo "\n=== Ilk 15 kategori (ID | PID | Tanim) ===\n";
foreach (array_slice($list, 0, 15) as $k) {
    echo '  '.str_pad((string) ($k->ID ?? '?'), 5).' | '
       .str_pad((string) ($k->PID ?? '?'), 5).' | '
       .($k->Tanim ?? '?')."\n";
}

// Bir ornek urunun kategori bilgilerini gor
$svc = ProductService::for('ana');
$products = $svc->getNewProducts(null, 1, 5, 'DESC');
foreach ($products as $p) {
    if (str_contains($p['UrunAdi'] ?? '', 'Meri Meri')) {
        echo "\n=== Ornek urun: ".$p['UrunAdi']." ===\n";
        echo '  AnaKategori: '.($p['AnaKategori'] ?? '?').' (AnaKategoriID='.($p['AnaKategoriID'] ?? '?').")\n";
        $kat = $p['Kategoriler']['int'] ?? $p['Kategoriler'] ?? [];
        if (! is_array($kat)) {
            $kat = [$kat];
        }
        echo '  Kategoriler dizisi: '.json_encode($kat)."\n";

        // Bu ID'lerin agacta yolunu cikart
        $byId = [];
        foreach ($list as $k) {
            $byId[(int) $k->ID] = $k;
        }
        foreach ($kat as $kid) {
            $path = [];
            $cur = $byId[(int) $kid] ?? null;
            while ($cur) {
                array_unshift($path, ($cur->Tanim ?? '?').'(#'.$cur->ID.')');
                $cur = ($cur->PID ?? 0) > 0 ? ($byId[(int) $cur->PID] ?? null) : null;
            }
            echo '    Yol: '.implode(' > ', $path)."\n";
        }
        break;
    }
}
